<?php
/**
 * The pairs of units on two WordPress sites (Z8b branch 2b; plan E2, 3.1
 * Z44, F55, Frage F): site B is the staging site, in processes of its own;
 * site A, this process, the production site with B's key a person confirmed.
 *
 * The normal case first (F55, over the public way, no pair put there by the
 * test): two sites alike → wp_site_pairs propose with the environment of
 * both sides → confirm with the token → a plugin updated on staging → the
 * report says «changed on staging, unchanged here». Then the units that
 * differ already (Frage F: b live_base, c keep_apart, a without a mode), a
 * unit on one side only (no pair, «only live» apart from a removal on
 * staging), the units that are not paired (not read whole, locked, held, a
 * name too long), every clause of AB_MCP_Sync_Pairs::set_unit_base() (Z44),
 * no way past it, and the base with origin push (F5, the contract only, by
 * the seam; branches 5 and 6 bring the writing).
 *
 * WIRKUNGEN NACH AUSSEN × FEHLERARTEN (plan 8.3, branch 2b):
 * Rows of the pairs (seam naht_sync_pairs: row, update_row, insert_row):
 * - return false at the insert, a read that fails (Lesefehler) and no row
 *   changed at the new alignment, Throwable at the insert, a writer in
 *   between (gleichzeitiger Schreiber, before the insert and before the
 *   update): testAFaultOfTheRowsOfThePairsWhileUnitsAreConfirmedKeepsNothing;
 * - exit/Fatal and the time limit of PHP at the insert, before and after it,
 *   in a process of its own: testAnEndOfTheProcessOrItsTimeLimitAtTheBaseOfAUnitKeepsNothingHalf;
 * - halb geschrieben: does not apply, a pair is one INSERT or one UPDATE of
 *   one row (all columns or none), and a confirmation is one transaction.
 * The files of the proposals and the reading of the environment of both
 * sides (seam naht_sync_proposal: put, get, files, groups, open, remove,
 * environment, live_environment):
 * - return false and an error at a write, a read that fails or gives other
 *   bytes (Lesefehler, halb gelesen), Throwable, the series of the staging
 *   site refused, the environment of this site not read to its end in one
 *   call (Zeitlimit of the budget):
 *   testAFaultOfTheFilesOfAProposalOrOfTheReadingOfTheEnvironmentPairsNoUnit;
 * - exit/Fatal and the time limit of PHP while the proposal stores its
 *   entries, in a process of its own: the next call works it out again:
 *   testAnEndOfTheProcessWhileTheProposalOfUnitsIsStoredLeavesItToTheNextCall;
 * - gleichzeitiger Schreiber: a unit changed between the proposal and the
 *   confirmation (the report shows it changed here, Z3 locks; the bases are
 *   the ones the proposal read whole): testTheTokenBindsTheUnitItsPartsTheirModesAndValues;
 *   two calls on one proposal at once: SyncProposalTest
 *   (testAProposalNotDoneIsNotConfirmedAndTwoCallsAtOnceVoidIt), the same
 *   files for units.
 * - halb geschrieben: a file of the proposal is named by its SHA-256 and read
 *   only against it; one cut short is no file of the proposal (in the test
 *   above, halb gelesen).
 * The token (AB_MCP_Confirm_Token; no call to the outside of its own):
 * - another mode, part or unit than the preview, a pair made in between, a
 *   Throwable while the state is worked out:
 *   testTheTokenBindsTheUnitItsPartsTheirModesAndValues; the plan written is
 *   the one the token was checked against (V1):
 *   SyncProposalTest::testTheConfirmationWritesThePlanItsTokenWasCheckedAgainst.
 * Hooks of others: none new (plan 8.3).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Code;
use AB_MCP_IT_Process;
use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Protected_Store;
use AB_MCP_REST_Controller;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Decision;
use AB_MCP_Sync_Env;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Pairs_Calls;
use AB_MCP_Sync_Pairs_Tool;
use AB_MCP_Sync_Proposal;
use AB_MCP_Sync_Proposal_Calls;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Schema;
use AB_MCP_Sync_Unit_Proof;
use AB_MCP_Test_Seam;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncUnitPairsTest extends TestCase {

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	/** @var string[] Files a case put on a site, removed after it. */
	private $made = array();

	/** @var string[] Folders a case made on a site (empty after its files), removed after it in this order. */
	private $folders = array();

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		wp_set_current_user( self::admin() );
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		foreach ( $this->made as $path ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->made = array();
		foreach ( $this->folders as $path ) {
			if ( is_dir( $path ) ) {
				rmdir( $path );
			}
		}
		$this->folders = array();
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Report::$env_lines = null;
		AB_MCP_Sync_Env::$clock        = null;
		AB_MCP_Sync_Env::forget();
		AB_MCP_Sync_Proposal::forget();
		AB_MCP_Sync_Pairs::forget();
		AB_MCP_IT_Code::clear();
		remove_all_filters( 'ab_mcp_redact_keys' );
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Code::clear();
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
	}

	/* ---------------------------------------------------------- helpers */

	private static function admin(): int {
		return (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'];
	}

	private static function wipe(): void {
		$root  = (string) AB_MCP_IT_Sites::state()['root'];
		$store = $root . '/ab-mcp-store';
		if ( is_dir( $store ) ) {
			self::remove_tree( $store, $root );
		}
	}

	private static function remove_tree( string $path, string $root ): void {
		if ( 0 !== strpos( $path, $root . '/' ) ) {
			throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
		}
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry, $root );
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

	private static function source(): string {
		return (string) AB_MCP_Sync_Anchor::active()['source_id'];
	}

	/** B packs one purpose (a series) and A takes it over: the package ids (the exports of B before it removed: one account keeps three). */
	private static function staged( string $purpose ): array {
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
		$ids    = array();
		$cursor = null;
		do {
			$step = array(
				'op'      => 'export',
				'purpose' => $purpose,
				'as'      => 'package',
			);
			if ( null !== $cursor ) {
				$step['cursor'] = $cursor;
			}
			$package = self::b( array( $step ) )['package'];
			$cursor  = $package['cursor'];
			$state   = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $package['package_id'],
					'manifest'   => $package['manifest'],
					'purpose'    => $purpose,
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
			$ids[] = $package['package_id'];
		} while ( null !== $cursor && count( $ids ) < 50 );
		self::assertNull( $cursor );
		return $ids;
	}

	/** wp_site_pairs in this process (propose and list run at once). */
	private static function here( array $a ) {
		return AB_MCP_Sync_Pairs_Tool::call( $a );
	}

	/** The definition of wp_site_pairs. */
	private static function def(): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r->get( 'wp_site_pairs' );
	}

	/** wp_site_pairs through the gate of the plugin, in this process. */
	private static function gated( array $a ) {
		return AB_MCP_REST_Controller::run_tool( 'wp_site_pairs', self::def(), $a );
	}

	/** A confirmation in this process: preview, then the same call with its token; both answers. */
	private static function confirmed( array $a ): array {
		$preview = self::gated( $a );
		if ( is_wp_error( $preview ) ) {
			return array( $preview, $preview );
		}
		self::assertTrue( $preview['confirm_required'], (string) json_encode( $preview ) );
		return array( $preview, self::gated( $a + array( 'confirm_token' => $preview['confirm_token'] ) ) );
	}

	private static function refused( $result, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $result, (string) json_encode( $result ) );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_message();
	}

	/** The clause a refusal of set_unit_base() names. */
	private static function why( $result ): string {
		self::refused( $result, 'ab_mcp_staging_pair_unit' );
		return (string) $result->get_error_data()['clause'];
	}

	/** A proposal with the environment, worked out to its end in this process: every entry in all. */
	private static function proposal( ?array $packages = null, ?array $environment = null ): array {
		$answer = self::here(
			array(
				'action'      => 'propose',
				'packages'    => $packages ?? self::staged( 'inventory' ),
				'environment' => $environment ?? self::staged( 'env' ),
			)
		);
		$calls  = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::here(
				array(
					'action'      => 'propose',
					'proposal_id' => $answer['proposal_id'],
				)
			);
			self::assertLessThan( 300, ++$calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$all  = $answer['entries'];
		$page = $answer;
		while ( null !== $page['next_after'] ) {
			$page = self::here(
				array(
					'action'      => 'propose',
					'proposal_id' => $answer['proposal_id'],
					'after'       => $page['next_after'],
				)
			);
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
		}
		$answer['all']   = $all;
		$answer['units'] = array();
		foreach ( $all as $entry ) {
			if ( ! empty( $entry['unit'] ) ) {
				$answer['units'][ $entry['kind'] . ':' . $entry['ref'] ] = $entry;
			}
		}
		return $answer;
	}

	/** The entries of the environment of a report worked out to its end, by subtype:ref. */
	private static function report(): array {
		$answer = AB_MCP_Sync_Report::call(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => self::staged( 'env' ),
			)
		);
		$calls  = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
			self::assertLessThan( 300, ++$calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$all  = $answer['entries'];
		$page = $answer;
		while ( null !== $page['cursor'] ) {
			$page = AB_MCP_Sync_Report::call(
				array(
					'report_id' => $answer['report_id'],
					'cursor'    => $page['cursor'],
				)
			);
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
		}
		$out = array();
		foreach ( $all as $entry ) {
			self::assertNotContains( $entry['kind'], AB_MCP_Sync_Decision::UNIT_KINDS, 'A pair of a unit is no object of the report.' );
			if ( 'environment' === $entry['kind'] && 'more_paths' !== $entry['result'] ) {
				$out[ $entry['subtype'] . ':' . $entry['ref'] ] = $entry;
			}
		}
		return $out;
	}

	/** The pairs of the source by kind:ref (live_ref). */
	private static function pairs(): array {
		$out = array();
		foreach ( AB_MCP_Sync_Pairs::page_source( self::source() )['pairs'] as $pair ) {
			$out[ $pair['kind'] . ':' . $pair['live_ref'] ] = $pair;
		}
		return $out;
	}

	/** The lines of the units of this site, read whole now, by kind:ref. */
	private static function lines_here(): array {
		$read = AB_MCP_Sync_Env::read( null, 60, 4000, 'live' );
		self::assertIsArray( $read );
		self::assertNull( $read['state'] );
		$out = array();
		foreach ( $read['lines'] as $line ) {
			if ( isset( $line['ref'] ) && 'entries' !== $line['env'] ) {
				$out[ $line['env'] . ':' . $line['ref'] ] = $line;
			}
		}
		return $out;
	}

	/** The lines of the units of the staging site from a series of its environment taken over now, by kind:ref. */
	private static function lines_there(): array {
		$env = AB_MCP_Sync_Report::environment( self::staged( 'env' ) );
		self::assertIsArray( $env, is_wp_error( $env ) ? $env->get_error_message() : '' );
		$out = array();
		foreach ( $env['lines'] as $line ) {
			if ( isset( $line['ref'] ) && 'entries' !== $line['env'] ) {
				$out[ $line['env'] . ':' . $line['ref'] ] = $line;
			}
		}
		return $out;
	}

	/** A plugin of one file of its own on a site (removed after the case). */
	private function own_plugin( string $site, string $name, string $comment = '' ): string {
		$path         = AB_MCP_IT_Sites::docroot( $site ) . '/wp-content/plugins/' . $name;
		$this->made[] = $path;
		file_put_contents( $path, "<?php\n/*\n * Plugin Name: " . $name . "\n * Version: 1.0\n */\n" . $comment );
		return $path;
	}

	/* ------------------------------------------------- the normal case */

	public function testTwoSitesAlikePairTheirUnitsThroughTheProposalAndAnUpdateOnStagingIsThenAChangeOnStaging(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		self::assertSame( array(), self::pairs(), 'No pair before: nothing put there by the test (F55).' );

		$answer = self::proposal();
		$units  = $answer['units'];
		foreach ( array( 'plugin:ab-it-aktiv', 'plugin:ab-it-inaktiv', 'plugin:ab-it-einzeln.php', 'plugin:ab-it-zwei', 'theme:ab-it-eltern', 'theme:ab-it-kind', 'theme_choice:theme_choice' ) as $key ) {
			self::assertArrayHasKey( $key, $units, $key );
			self::assertSame( 'equal', $units[ $key ]['category'], $key . ': the same name and the same parts.' );
			self::assertSame( 'active', $units[ $key ]['confirm'], $key );
		}
		self::assertSame( array( 'activation' => 'equal', 'files' => 'equal' ), $units['plugin:ab-it-aktiv']['parts'] );
		self::assertSame( array( 'files' => 'equal' ), $units['theme:ab-it-eltern']['parts'] );
		self::assertSame( array( 'activation' => 'equal' ), $units['theme_choice:theme_choice']['parts'] );
		$here = self::lines_here();
		$text = (string) json_encode( $answer );
		self::assertStringNotContainsString( (string) $here['plugin:ab-it-aktiv']['hash'], $text, 'No hash of a manifest in the answer.' );
		self::assertArrayNotHasKey( 'lines', $units['plugin:ab-it-aktiv'] );
		self::assertSame( 'differs', $units['plugin:ab-it-schreiber']['category'], 'The plugin that writes its log here differs (F46).' );
		$own = array_values( array_filter( $units, static fn( $u ) => isset( $u['reason'] ) && false !== strpos( $u['reason'], 'AlphaBridge itself is never paired' ) ) );
		self::assertCount( 1, $own, 'AlphaBridge itself: named, never paired.' );
		self::assertSame( 'not_compared', $own[0]['category'] );

		// The person confirms every unit that is equal, with the token.
		list( $preview, $done ) = self::confirmed(
			array(
				'action'      => 'confirm',
				'proposal_id' => $answer['proposal_id'],
				'categories'  => array( 'equal' ),
			)
		);
		$ops = array();
		foreach ( $preview['preview']['pairs'] as $op ) {
			if ( 'unit' === $op['op'] ) {
				$ops[ $op['kind'] . ':' . $op['ref'] ] = $op;
			}
		}
		self::assertSame( array( 'files' => 'equal', 'activation' => 'equal' ), $ops['plugin:ab-it-aktiv']['parts'], 'The preview names each part with its mode.' );
		self::assertArrayNotHasKey( 'lines', $ops['plugin:ab-it-aktiv'] );
		self::assertStringContainsString( 'A unit (a plugin, a theme, the theme that runs) is paired', $preview['preview']['effect'] );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertTrue( $done['done'] );

		$pairs = self::pairs();
		$pair  = $pairs['plugin:ab-it-aktiv'];
		self::assertSame( array( 'active', 'proposal', 'ab-it-aktiv', AB_MCP_Sync_Canon::VERSION ), array( $pair['state'], $pair['base_origin'], $pair['staging_ref'], $pair['canon'] ) );
		$act = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $here['plugin:ab-it-aktiv'] );
		self::assertSame( array( $here['plugin:ab-it-aktiv']['hash'], $here['plugin:ab-it-aktiv']['hash'] ), array( $pair['base_live'], $pair['base_staging'] ), 'The files of the manifests read whole.' );
		self::assertSame( array( 'live' => $act, 'staging' => $act ), $pair['base_extra']['parts']['activation'], 'Which main files run.' );
		self::assertArrayHasKey( 'theme_choice:theme_choice', $pairs );
		self::assertArrayNotHasKey( 'plugin:ab-it-schreiber', $pairs, 'A unit that differs is not paired by its category.' );

		// The plugin updated on staging.
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-aktiv', '2.0.0' );
		$env = self::report();
		$e   = $env['plugin:ab-it-aktiv'];
		self::assertSame( 'changed_on_staging', $e['result'], 'F55: changed on staging, unchanged here.' );
		self::assertSame( array( 'activation' => 'equal', 'files' => 'changed_on_staging' ), $e['parts'] );
		self::assertSame( (int) $pair['id'], $e['pair']['id'] );
		foreach ( array( 'plugin:ab-it-inaktiv', 'theme:ab-it-eltern', 'theme_choice:theme_choice' ) as $key ) {
			self::assertSame( 'equal', $env[ $key ]['result'], $key . ': unchanged since its base on both sides.' );
			self::assertArrayHasKey( 'pair', $env[ $key ] );
		}

		// A proposal after it: the units with an active pair are not proposed again.
		$again = self::proposal();
		self::assertArrayNotHasKey( 'plugin:ab-it-aktiv', $again['units'] );
		self::assertGreaterThanOrEqual( count( $ops ), $again['paired'] );
	}

	/* ------------------------------------------ Frage F: units that differ */

	public function testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		// Updated on staging before the first proposal (the files differ).
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		// Both parts differ: a new version on staging, and deactivated there.
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-einzeln', '1.1.0' );
		AB_MCP_IT_Process::calls( 'b', array( 'calls' => array( array( 'call' => 'deactivate_plugins', 'args' => array( 'ab-it-einzeln.php', true ) ) ) ) );
		// Only which main file runs differs.
		AB_MCP_IT_Process::calls( 'b', array( 'calls' => array( array( 'call' => 'deactivate_plugins', 'args' => array( 'ab-it-basis/ab-it-basis.php', true ) ) ) ) );

		$answer = self::proposal();
		$units  = $answer['units'];
		$inakt  = $units['plugin:ab-it-inaktiv'];
		$einz   = $units['plugin:ab-it-einzeln.php'];
		$basis  = $units['plugin:ab-it-basis'];
		self::assertSame( array( 'differs', 'modes', array( 'files' ) ), array( $inakt['category'], $inakt['confirm'], $inakt['differs'] ) );
		self::assertSame( array( 'activation' => 'equal', 'files' => 'differs' ), $inakt['parts'] );
		self::assertSame( array( 'files', 'activation' ), $einz['differs'] );
		self::assertSame( array( 'activation' ), $basis['differs'] );
		self::assertArrayHasKey( 'paths', $inakt, 'The differences are shown before a mode is chosen.' );
		self::assertStringContainsString( 'live_base', $inakt['note'] );

		$c = static fn( array $more ): array => array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
		) + $more;

		// a: without a mode, a unit that differs stays unclear.
		self::assertStringContainsString( 'in units', self::refused( self::gated( $c( array( 'select' => array( $inakt['n'] ) ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		// By the category, the objects of other content come, a unit not.
		$by_category = self::gated( $c( array( 'categories' => array( 'differs' ) ) ) );
		self::assertSame( array(), array_values( array_filter( $by_category['preview']['pairs'], static fn( $op ) => 'unit' === $op['op'] ) ) );
		$skipped = array_column( $by_category['preview']['skipped'], 'reason', 'entry' );
		foreach ( array( $inakt, $einz, $basis ) as $unit ) {
			self::assertStringContainsString( 'paired only with the mode a person chose', $skipped[ $unit['n'] ], $unit['ref'] );
		}
		// A mode for a part that is equal, or one part of two left out.
		self::assertStringContainsString( 'for each part that differs and for no other', self::refused( self::gated( $c( array( 'units' => array( array( 'entry' => $inakt['n'], 'part' => 'activation', 'mode' => 'live_base' ) ) ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		self::assertStringContainsString( 'for each part that differs and for no other', self::refused( self::gated( $c( array( 'units' => array( array( 'entry' => $einz['n'], 'part' => 'files', 'mode' => 'keep_apart' ) ) ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		self::assertSame( 'ab_mcp_sync_pairs_selection', self::gated( $c( array( 'units' => array( array( 'entry' => $inakt['n'], 'part' => 'files', 'mode' => 'equal' ) ) ) ) )->get_error_code(), 'equal is no mode a person chooses.' );
		self::assertStringContainsString( 'is no unit whose parts differ', self::refused( self::gated( $c( array( 'units' => array( array( 'entry' => $units['plugin:ab-it-zwei']['n'], 'part' => 'files', 'mode' => 'live_base' ) ) ) ) ), 'ab_mcp_sync_pairs_selection' ), 'A unit that is equal takes no mode.' );
		self::assertSame( 'ab_mcp_sync_pairs_selection', self::gated( $c( array( 'units' => array( array( 'entry' => $inakt['n'], 'part' => 'files', 'mode' => 'live_base' ), array( 'entry' => $inakt['n'], 'part' => 'files', 'mode' => 'keep_apart' ) ) ) ) )->get_error_code(), 'Each part of an entry once.' );
		self::assertSame( array(), self::pairs(), 'Nothing paired.' );

		// b and c, part by part, after the preview names what each means.
		$args                   = $c(
			array(
				'units' => array(
					array( 'entry' => $inakt['n'], 'part' => 'files', 'mode' => 'live_base' ),
					array( 'entry' => $einz['n'], 'part' => 'files', 'mode' => 'keep_apart' ),
					array( 'entry' => $einz['n'], 'part' => 'activation', 'mode' => 'live_base' ),
					array( 'entry' => $basis['n'], 'part' => 'activation', 'mode' => 'keep_apart' ),
				),
			)
		);
		list( $preview, $done ) = self::confirmed( $args );
		$named                  = array();
		foreach ( $preview['preview']['pairs'] as $op ) {
			$named[ $op['ref'] ] = $op;
		}
		self::assertSame( array( 'files' => 'live_base', 'activation' => 'equal' ), $named['ab-it-inaktiv']['parts'] );
		foreach ( array( 'does not prove that the two sites ever had this version in common', 'can no longer be told from these bases', 'belongs to this base', 'put it into the backup with the old folder or replace it with what staging has', 'still locks a takeover (code:live_changed)', 'no older version replaces a newer one' ) as $said ) {
			self::assertStringContainsString( $said, $named['ab-it-inaktiv']['effect'], 'Frage F, b: ' . $said );
		}
		self::assertStringContainsString( 'never taken over', $named['ab-it-einzeln.php']['effect'], 'Frage F, c.' );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertStringContainsString( 'does not prove', ( $done['pairs'][0]['effect'] ?? '' ) . ( $done['pairs'][1]['effect'] ?? '' ), 'The answer says it again.' );

		$here  = self::lines_here();
		$there = self::lines_there();
		$pairs = self::pairs();
		$p     = $pairs['plugin:ab-it-inaktiv'];
		self::assertSame( array( $here['plugin:ab-it-inaktiv']['hash'], $here['plugin:ab-it-inaktiv']['hash'] ), array( $p['base_live'], $p['base_staging'] ), 'b: the state of this site is the base of both sides.' );
		$p = $pairs['plugin:ab-it-einzeln.php'];
		self::assertSame( array( $here['plugin:ab-it-einzeln.php']['hash'], $there['plugin:ab-it-einzeln.php']['hash'] ), array( $p['base_live'], $p['base_staging'] ), 'c: each side its own base.' );
		$act = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $here['plugin:ab-it-einzeln.php'] );
		self::assertSame( array( 'live' => $act, 'staging' => $act ), $p['base_extra']['parts']['activation'], 'b for the other part of the same unit.' );

		$env = self::report();
		self::assertSame( array( 'changed_on_staging', array( 'activation' => 'equal', 'files' => 'changed_on_staging' ) ), array( $env['plugin:ab-it-inaktiv']['result'], $env['plugin:ab-it-inaktiv']['parts'] ), 'b: the difference is a change on staging.' );
		self::assertSame( array( 'activation' => 'changed_on_staging', 'files' => 'kept_apart' ), $env['plugin:ab-it-einzeln.php']['parts'] );
		self::assertSame( array( 'kept_apart', array( 'activation' => 'kept_apart', 'files' => 'equal' ) ), array( $env['plugin:ab-it-basis']['result'], $env['plugin:ab-it-basis']['parts'] ), 'c: kept apart, nothing to take over.' );
		self::assertSame( 'none', $env['plugin:ab-it-basis']['action'] );
	}

	/* -------------------------------------------- one side, not paired */

	public function testAUnitOnOneSideOnlyGetsNoPairAndOnlyLiveStaysApartFromARemovalOnStaging(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		$this->own_plugin( 'a', 'ab-it-nur-hier.php' );
		$this->own_plugin( 'b', 'ab-it-nur-dort.php' );
		$answer = self::proposal();
		$units  = $answer['units'];
		self::assertSame( 'live_only', $units['plugin:ab-it-nur-hier.php']['category'] );
		self::assertSame( 'staging_only', $units['plugin:ab-it-nur-dort.php']['category'] );
		foreach ( array( 'plugin:ab-it-nur-hier.php', 'plugin:ab-it-nur-dort.php' ) as $key ) {
			self::assertStringContainsString( 'is not paired', self::refused( self::gated( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'select' => array( $units[ $key ]['n'] ) ) ), 'ab_mcp_sync_pairs_selection' ), $key );
		}
		// The unit on both sides, paired, then removed on staging.
		list( , $done ) = self::confirmed( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'select' => array( $units['plugin:ab-it-zwei']['n'] ) ) );
		self::assertIsArray( $done );
		AB_MCP_IT_Code::remove( 'b', 'plugin', 'ab-it-zwei' );
		$env = self::report();
		self::assertSame( array( 'only_live', 'keep' ), array( $env['plugin:ab-it-nur-hier.php']['result'], $env['plugin:ab-it-nur-hier.php']['action'] ), 'Only live, without a pair: no removal on staging.' );
		self::assertArrayNotHasKey( 'pair', $env['plugin:ab-it-nur-hier.php'] );
		self::assertSame( 'deleted_on_staging', $env['plugin:ab-it-zwei']['result'], 'With its pair: a removal on staging it proves.' );
		self::assertSame( array( 'plugin:ab-it-zwei' ), array_keys( self::pairs() ) );

		// No base made up for the missing side.
		$here = self::lines_here();
		$line = $here['plugin:ab-it-nur-hier.php'];
		$op   = self::op( 'plugin', 'ab-it-nur-hier.php', array( 'files' => 'equal', 'activation' => 'equal' ), $line, $line );
		$op['lines']['staging'] = null;
		self::assertSame( 'side', self::why( AB_MCP_Sync_Pairs::set_unit_base( self::source(), $op, AB_MCP_Sync_Unit_Proof::confirmed( array( $op ) ) ) ) );
	}

	public function testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		// Z2: a folder .git here.
		$git           = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' ) . '/.git';
		mkdir( $git );
		$this->made[]  = $git . '/HEAD';
		$this->folders = array( $git );
		file_put_contents( $git . '/HEAD', 'ref' );
		// A name a credential holds, on both sides.
		$held = 'ab-it-password=EXAMPLE_SECRET.php';
		$this->own_plugin( 'a', $held );
		$this->own_plugin( 'b', $held );
		// A name longer than a pair holds, on both sides.
		$long = 'ab-it-' . str_repeat( 'l', 190 ) . '.php';
		$this->own_plugin( 'a', $long );
		$this->own_plugin( 'b', $long );
		// A file of a plugin here that cannot be read: not read whole.
		$locked = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-dienst' ) . '/ab-it-dienst.php';
		chmod( $locked, 0000 );
		try {
			$answer = self::proposal();
		} finally {
			chmod( $locked, 0644 );
		}
		$units = $answer['units'];
		$e     = $units['plugin:ab-it-inaktiv'];
		self::assertSame( 'not_compared', $e['category'] );
		self::assertStringContainsString( 'It is locked by what it holds', $e['reason'] );
		self::assertSame( array( 'code:live_extra' ), array_column( $e['locks'], 'key' ), 'Z2: the lock with its reason.' );
		$found = array_values( array_filter( $answer['units'], static fn( $u ) => 'held' === $u['category'] ) );
		self::assertCount( 1, $found, 'The held name, by its SHA-256 only.' );
		self::assertStringNotContainsString( 'EXAMPLE_SECRET', (string) json_encode( $answer ) );
		self::assertSame( 'not_compared', $units[ 'plugin:' . $long ]['category'] );
		self::assertStringContainsString( 'cannot be held by a pair', $units[ 'plugin:' . $long ]['reason'] );
		self::assertSame( 'not_compared', $units['plugin:ab-it-dienst']['category'] );
		self::assertStringContainsString( 'could not be read whole on this site', $units['plugin:ab-it-dienst']['reason'] );
		foreach ( array( $e, $found[0], $units[ 'plugin:' . $long ], $units['plugin:ab-it-dienst'] ) as $entry ) {
			self::assertSame( 'ab_mcp_sync_pairs_selection', self::gated( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'select' => array( $entry['n'] ) ) )->get_error_code() );
		}
		self::assertSame( array(), self::pairs() );
	}

	/* ------------------------------------------------ set_unit_base(), Z44 */

	/** A line of a unit as the environment writes it. */
	private static function line( string $kind, string $ref, array $over = array() ): array {
		if ( 'theme_choice' === $kind ) {
			return array_merge(
				array(
					'env'        => 'theme_choice',
					'ref'        => 'theme_choice',
					'stylesheet' => 'kind',
					'template'   => 'eltern',
				),
				$over
			);
		}
		return array_merge(
			array(
				'env'      => $kind,
				'ref'      => $ref,
				'active'   => 'plugin' === $kind ? array( 'main.php' ) : null,
				'bytes'    => 10,
				'cause'    => null,
				'complete' => true,
				'files'    => 1,
				'form'     => 'folder',
				'hash'     => hash( 'sha256', 'files-' . $ref ),
				'lock'     => null,
				'mains'    => 'plugin' === $kind ? array( 'main.php' ) : null,
				'mtime'    => null,
				'own'      => false,
				'sha256'   => null,
				'version'  => '1.0',
			),
			$over
		);
	}

	/** An operation of set_unit_base() with the values its lines give in its modes. */
	private static function op( string $kind, string $ref, array $modes, ?array $s, ?array $l, array $more = array() ): array {
		$parts = array();
		foreach ( $modes as $part => $mode ) {
			$hs             = null === $s ? null : AB_MCP_Sync_Decision::part_hash( $kind, $part, $s );
			$hl             = null === $l ? null : AB_MCP_Sync_Decision::part_hash( $kind, $part, $l );
			$parts[ $part ] = array(
				'base_live'    => $hl,
				'base_staging' => 'keep_apart' === $mode ? $hs : $hl,
				'mode'         => $mode,
			);
		}
		return array_merge(
			array(
				'op'       => 'unit',
				'kind'     => $kind,
				'ref'      => $ref,
				'pair_id'  => null,
				'expected' => null,
				'parts'    => $parts,
				'lines'    => array(
					'staging' => $s,
					'live'    => $l,
				),
			),
			$more
		);
	}

	/** set_unit_base() of an operation with a confirmation that holds it. */
	private static function set( array $op ) {
		return AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $op, AB_MCP_Sync_Unit_Proof::confirmed( array( $op ) ) );
	}

	public function testSetUnitBaseHoldsEachClauseOfZ44(): void {
		$s    = self::line( 'plugin', 'shop', array( 'hash' => hash( 'sha256', 'v2' ) ) );
		$l    = self::line( 'plugin', 'shop', array( 'hash' => hash( 'sha256', 'v1' ) ) );
		$good = self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, $l );
		$rows = static fn(): int => count( AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );

		// Each clause by an input that breaks it alone.
		$cases = array(
			'kind'          => array( self::op( 'post', 'shop', array( 'files' => 'equal' ), $s, $l ), 'kind' ),
			'ref too long'  => array( self::op( 'plugin', str_repeat( 's', 192 ), array( 'files' => 'live_base', 'activation' => 'equal' ), self::line( 'plugin', str_repeat( 's', 192 ) ), self::line( 'plugin', str_repeat( 's', 192 ), array( 'hash' => hash( 'sha256', 'x' ) ) ) ), 'ref' ),
			'ref held'      => array( self::op( 'plugin', 'sha256:' . hash( 'sha256', 'p' ), array( 'files' => 'equal', 'activation' => 'equal' ), self::line( 'plugin', 'sha256:' . hash( 'sha256', 'p' ) ), self::line( 'plugin', 'sha256:' . hash( 'sha256', 'p' ) ) ), 'ref' ),
			'a part alone'  => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base' ), $s, $l ), 'parts' ),
			'a part more'   => array( self::op( 'theme', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), self::line( 'theme', 'shop', array( 'hash' => hash( 'sha256', 'v2' ) ) ), self::line( 'theme', 'shop' ) ), 'parts' ),
			'mode of push'  => array( self::op( 'plugin', 'shop', array( 'files' => 'push', 'activation' => 'equal' ), $s, $l ), 'mode' ),
			'no mode'       => array( array_replace_recursive( $good, array( 'parts' => array( 'files' => array( 'mode' => 'other' ) ) ) ), 'mode' ),
			'no line'       => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, null ), 'side' ),
			'other name'    => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), self::line( 'plugin', 'shop-2', array( 'hash' => hash( 'sha256', 'v2' ) ) ), $l ), 'side' ),
			'other kind'    => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), array( 'env' => 'theme' ) + $s, $l ), 'side' ),
			'not complete'  => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, array( 'complete' => false ) + $l ), 'whole' ),
			'no form'       => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, array( 'form' => 'other' ) + $l ), 'whole' ),
			'locked'        => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), array( 'lock' => 'code:link' ) + $s, $l ), 'whole' ),
			'held'          => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, array( 'held' => 'credential_field' ) + $l ), 'whole' ),
			'own'           => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, array( 'own' => true ) + $l ), 'whole' ),
			'no hash'       => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, array( 'hash' => null ) + $l ), 'whole' ),
			'running not read' => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, array( 'active' => null ) + $l ), 'whole' ),
			'mains not read'   => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, array( 'mains' => null ) + $l ), 'whole' ),
			'theme not read'   => array( self::op( 'theme_choice', 'theme_choice', array( 'activation' => 'equal' ), self::line( 'theme_choice', 'theme_choice' ), self::line( 'theme_choice', 'theme_choice', array( 'template' => null ) ) ), 'whole' ),
			'equal, differs'   => array( self::op( 'plugin', 'shop', array( 'files' => 'equal', 'activation' => 'equal' ), $s, $l ), 'state' ),
			'b, the same'      => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'live_base' ), $s, $l ), 'state' ),
			'c, the same'      => array( self::op( 'plugin', 'shop', array( 'files' => 'keep_apart', 'activation' => 'keep_apart' ), $s, $l ), 'state' ),
			'values of b'      => array( array_replace_recursive( $good, array( 'parts' => array( 'files' => array( 'base_staging' => hash( 'sha256', 'v2' ) ) ) ) ), 'bound' ),
			'values live'      => array( array_replace_recursive( $good, array( 'parts' => array( 'files' => array( 'base_live' => hash( 'sha256', 'v2' ) ) ) ) ), 'bound' ),
		);
		foreach ( $cases as $case => list( $op, $why ) ) {
			self::assertSame( $why, self::why( self::set( $op ) ), $case );
			self::assertSame( 0, $rows(), $case . ': nothing written.' );
		}
		// The confirmation holds the very operation; none, or one of another.
		self::assertSame( 'proof', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $good, null ) ) );
		self::assertSame( 'proof', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $good, AB_MCP_Sync_Unit_Proof::confirmed( array( self::op( 'plugin', 'shop', array( 'files' => 'keep_apart', 'activation' => 'equal' ), $s, $l ) ) ) ) ) );
		self::assertSame( 'mode', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $good, AB_MCP_Sync_Unit_Proof::pushed( array( 'files' ) ) ) ), 'A confirmation of a person is no step.' );
		self::assertSame( 0, $rows() );

		// The pair, each part as its mode says.
		$pair = self::set( $good );
		self::assertIsArray( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		$act = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $l );
		self::assertSame( array( 'active', 'proposal', 'shop', 'shop', $l['hash'], $l['hash'] ), array( $pair['state'], $pair['base_origin'], $pair['live_ref'], $pair['staging_ref'], $pair['base_live'], $pair['base_staging'] ) );
		self::assertSame( array( 'parts' => array( 'activation' => array( 'live' => $act, 'staging' => $act ) ) ), $pair['base_extra'] );
		self::assertSame( 'ab_mcp_staging_pair_conflict', self::set( $good )->get_error_code(), 'One pair a unit.' );
		$c = self::set( self::op( 'theme', 'look', array( 'files' => 'keep_apart' ), self::line( 'theme', 'look', array( 'hash' => hash( 'sha256', 'st' ) ) ), self::line( 'theme', 'look' ) ) );
		self::assertSame( array( hash( 'sha256', 'files-look' ), hash( 'sha256', 'st' ), null ), array( $c['base_live'], $c['base_staging'], $c['base_extra'] ), 'c: each side its own base; a theme has one part.' );
		$t = self::set( self::op( 'theme_choice', 'theme_choice', array( 'activation' => 'live_base' ), self::line( 'theme_choice', 'theme_choice', array( 'stylesheet' => 'other' ) ), self::line( 'theme_choice', 'theme_choice' ) ) );
		$h = AB_MCP_Sync_Canon::hash( array( 'stylesheet' => 'kind', 'template' => 'eltern' ) );
		self::assertSame( array( $h, $h ), array( $t['base_live'], $t['base_staging'] ), 'The theme that runs, from both its rows.' );

		// A new alignment: only of a pair unclear or residual, of this very
		// unit, as the person saw it; every part anew, the rest of base_extra kept.
		$expected = static fn( array $p ): string => AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $p['id'] ) );
		$realign  = self::op( 'plugin', 'shop', array( 'files' => 'keep_apart', 'activation' => 'equal' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $pair ) ) );
		self::assertSame( 'pair_state', self::why( self::set( $realign ) ), 'An active pair moves on part by part only (rebase()).' );
		global $wpdb;
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'state' => 'unclear', 'base_extra' => '{"other":1,"parts":{"activation":{"live":"' . str_repeat( 'a', 64 ) . '","staging":"' . str_repeat( 'b', 64 ) . '"}}}' ), array( 'id' => (int) $pair['id'] ) ); // phpcs:ignore WordPress.DB
		$unclear  = AB_MCP_Sync_Pairs::get( (int) $pair['id'] );
		$realign  = self::op( 'plugin', 'shop', array( 'files' => 'keep_apart', 'activation' => 'equal' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => hash( 'sha256', 'old' ) ) );
		self::assertSame( 'ab_mcp_staging_pair_changed', self::set( $realign )->get_error_code(), 'As the person saw it.' );
		$other    = self::op( 'theme', 'look', array( 'files' => 'equal' ), self::line( 'theme', 'look' ), self::line( 'theme', 'look' ), array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $unclear ) ) );
		self::assertSame( 'identity', self::why( self::set( $other ) ) );
		$realign  = self::op( 'plugin', 'shop', array( 'files' => 'keep_apart', 'activation' => 'equal' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $unclear ) ) );
		$now      = self::set( $realign );
		self::assertIsArray( $now, is_wp_error( $now ) ? $now->get_error_message() : '' );
		self::assertSame( array( 'active', 'proposal', $l['hash'], $s['hash'] ), array( $now['state'], $now['base_origin'], $now['base_live'], $now['base_staging'] ) );
		self::assertSame( array( 'other' => 1, 'parts' => array( 'activation' => array( 'live' => $act, 'staging' => $act ) ) ), $now['base_extra'], 'Every part anew, the other keys kept.' );
		self::assertSame( 'written', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', self::op( 'plugin', 'shop', array( 'files' => 'push', 'activation' => 'push' ), $s, $s, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $now ) ) ), AB_MCP_Sync_Unit_Proof::pushed( array( 'files' ) ) ) ), 'push makes a pair, never aligns one.' );
	}

	/* ---------------------------------------------- no way past it, Z44 */

	public function testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit(): void {
		$fields = array(
			'source_id'    => 'src-2b',
			'kind'         => 'plugin',
			'live_ref'     => 'shop',
			'staging_ref'  => 'shop',
			'canon'        => AB_MCP_Sync_Canon::VERSION,
			'base_live'    => hash( 'sha256', 'l' ),
			'base_staging' => hash( 'sha256', 's' ),
			'base_origin'  => 'proposal',
		);
		foreach ( array_keys( AB_MCP_Sync_Pairs::PARTS ) as $kind ) {
			$made = AB_MCP_Sync_Pairs::create( array( 'kind' => $kind ) + $fields );
			self::assertSame( array( 'ab_mcp_staging_pair_unit', 'unit_only', 'create' ), array( $made->get_error_code(), $made->get_error_data()['clause'], $made->get_error_data()['call'] ), $kind );
		}
		foreach ( array( 'push', 'clone', 'revert' ) as $origin ) {
			self::assertSame( 'ab_mcp_staging_pair_unit', AB_MCP_Sync_Pairs::create( array( 'base_origin' => $origin ) + $fields )->get_error_code(), 'Of any origin: ' . $origin );
		}
		self::assertSame( array(), AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );
		self::assertIsArray( AB_MCP_Sync_Pairs::create( array( 'kind' => 'post' ) + $fields ), 'An object as before.' );

		// A pair of a unit as a step left it unclear: realign() and
		// change_canon() leave it, rebase() makes no first base.
		$pair     = \AB_MCP_IT_Pair_Rows::put( array( 'state' => 'unclear' ) + $fields );
		$snapshot = AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] );
		$expected = AB_MCP_Sync_Pairs::snapshot_hash( $snapshot );
		$result   = AB_MCP_Sync_Pairs::realign( (int) $pair['id'], array( 'canon' => AB_MCP_Sync_Canon::VERSION, 'base_live' => hash( 'sha256', 'x' ), 'base_staging' => hash( 'sha256', 'x' ) ), $expected, true );
		self::assertSame( array( 'ab_mcp_staging_pair_unit', 'realign' ), array( $result->get_error_code(), $result->get_error_data()['call'] ) );
		$result = AB_MCP_Sync_Pairs::change_canon( (int) $pair['id'], 'canon/9', static fn() => array( 'live' => hash( 'sha256', 'x' ), 'staging' => hash( 'sha256', 'x' ), 'target' => hash( 'sha256', 'x' ) ) );
		self::assertSame( array( 'ab_mcp_staging_pair_unit', 'change_canon' ), array( $result->get_error_code(), $result->get_error_data()['call'] ) );
		$result = AB_MCP_Sync_Pairs::rebase( (int) $pair['id'], array( 'canon' => AB_MCP_Sync_Canon::VERSION, 'base_live' => hash( 'sha256', 'x' ), 'base_staging' => hash( 'sha256', 'x' ), 'base_origin' => 'push' ), $expected, 'files', null );
		self::assertSame( 'ab_mcp_staging_pair_not_active', $result->get_error_code(), 'rebase() moves on the bases of an active pair only (Z38).' );
		self::assertSame( $snapshot, AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ), 'Nothing written.' );
		$other = \AB_MCP_IT_Pair_Rows::put( array( 'live_ref' => 'other', 'staging_ref' => 'other' ) + $fields );
		$put   = AB_MCP_Sync_Pairs::restore( (int) $other['id'], $snapshot, AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $other['id'] ) ) );
		self::assertSame( 'ab_mcp_staging_pair_snapshot_foreign', $put->get_error_code(), 'restore() puts back only the pair its snapshot is of.' );
	}

	/* ------------------------------------------- origin push (F5), contract */

	public function testAUnitMadeOnThisSiteTakesABaseWithOriginPushOnlyWithItsFilesWrittenAndChecked(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		AB_MCP_IT_Code::remove( 'a', 'plugin', 'ab-it-inaktiv' );
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		AB_MCP_IT_Process::calls( 'b', array( 'calls' => array( array( 'call' => 'activate_plugin', 'args' => array( 'ab-it-inaktiv/ab-it-inaktiv.php' ), 'expect' => 'result' ) ) ) );
		$there  = self::lines_there();
		$s      = $there['plugin:ab-it-inaktiv'];
		$source = self::source();
		$pushed = static fn( array $written, array $l, array $more = array() ) => AB_MCP_Sync_Pairs::set_unit_base( $source, self::op( 'plugin', 'ab-it-inaktiv', array( 'files' => 'push', 'activation' => 'push' ), $s, $l, $more ), AB_MCP_Sync_Unit_Proof::pushed( $written ) );

		// Not written yet, or written with another version: no base.
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.0.0' );
		$other = self::lines_here()['plugin:ab-it-inaktiv'];
		self::assertSame( 'state', self::why( $pushed( array( 'files' ), $other ) ), 'Written, but not as staging has it: checked, no base.' );
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$l = self::lines_here()['plugin:ab-it-inaktiv'];
		self::assertSame( 'written', self::why( $pushed( array(), $l ) ), 'Nothing written.' );
		self::assertSame( 'written', self::why( $pushed( array( 'activation' ), $l ) ), 'The files not written.' );
		self::assertSame( 'written', self::why( $pushed( array( 'files', 'other' ), $l ) ) );
		self::assertSame( 'whole', self::why( $pushed( array( 'files' ), array( 'complete' => false, 'hash' => null ) + $l ) ), 'Not checked: the line after the write not read whole.' );
		self::assertSame( 'side', self::why( AB_MCP_Sync_Pairs::set_unit_base( $source, self::op( 'plugin', 'ab-it-inaktiv', array( 'files' => 'push', 'activation' => 'push' ), $s, null ), AB_MCP_Sync_Unit_Proof::pushed( array( 'files' ) ) ) ), 'No line after the write: unchecked.' );
		self::assertSame( array(), self::pairs() );

		// Written and checked: the files as staging has them, the activation
		// where this site stands after it (made inactive), so the activation on
		// staging is a change on staging, which the activation step takes over.
		$pair = $pushed( array( 'files' ), $l, array( 'last_run' => 7 ) );
		self::assertIsArray( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		$act = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $l );
		self::assertSame( array( 'push', 7, $s['hash'], $s['hash'] ), array( $pair['base_origin'], $pair['last_run'], $pair['base_live'], $pair['base_staging'] ) );
		self::assertSame( array( 'live' => $act, 'staging' => $act ), $pair['base_extra']['parts']['activation'] );
		self::assertSame( 'written', self::why( $pushed( array( 'files' ), $l, array( 'pair_id' => (int) $pair['id'] ) ) ), 'Only for a unit without a pair.' );
		$env = self::report();
		self::assertSame( array( 'activation' => 'changed_on_staging', 'files' => 'equal' ), $env['plugin:ab-it-inaktiv']['parts'], 'F5: the activation follows as a change on staging.' );
	}

	/* ------------------------------------- faults of the rows of the pairs */

	public function testAFaultOfTheRowsOfThePairsWhileUnitsAreConfirmedKeepsNothing(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		$answer  = self::proposal();
		$units   = $answer['units'];
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( $units['plugin:ab-it-aktiv']['n'], $units['plugin:ab-it-inaktiv']['n'] ),
		);
		$preview = self::gated( $args );
		self::assertTrue( $preview['confirm_required'] );
		$token   = array( 'confirm_token' => $preview['confirm_token'] );
		$seam    = static function ( array $faults ): void {
			AB_MCP_Test_Seam::restore_all();
			AB_MCP_Test_Seam::install( AB_MCP_Sync_Pairs::class, 'naht_sync_pairs', AB_MCP_Sync_Pairs_Calls::class, $faults );
		};
		$cases = array(
			'the insert of the second unit fails (Rückgabe false)' => array( array( 'method' => 'insert_row', 'nth' => 2, 'kind' => 'return', 'value' => false ), 'ab_mcp_sync_pairs_confirm_failed' ),
			'the insert of the second unit throws (Throwable)'     => array( array( 'method' => 'insert_row', 'nth' => 2, 'kind' => 'throw', 'message' => 'rows gone' ), 'ab_mcp_internal' ),
		);
		foreach ( $cases as $case => list( $fault, $code ) ) {
			$seam( array( $fault ) );
			$result = self::gated( $args + $token );
			AB_MCP_Test_Seam::restore_all();
			self::refused( $result, $code );
			self::assertSame( array(), self::pairs(), $case . ': the first unit taken back with it.' );
		}
		// Another request makes the pair of the second unit right before the
		// insert (gleichzeitiger Schreiber, its own connection).
		$fields = array(
			'source_id'    => self::source(),
			'kind'         => 'plugin',
			'live_ref'     => 'ab-it-inaktiv',
			'staging_ref'  => 'ab-it-inaktiv',
			'canon'        => AB_MCP_Sync_Canon::VERSION,
			'base_live'    => hash( 'sha256', 'other' ),
			'base_staging' => hash( 'sha256', 'other' ),
			'base_origin'  => 'proposal',
		);
		$seam(
			array(
				array(
					'method' => 'insert_row',
					'nth'    => 2,
					'kind'   => 'before',
					'before' => static function () use ( $fields ) {
						AB_MCP_IT_Process::calls(
							'a',
							array(
								'require' => array( __DIR__ . '/support/class-it-pair-rows.php' ),
								'calls'   => array( array( 'call' => 'AB_MCP_IT_Pair_Rows::put', 'args' => array( $fields ) ) ),
							)
						);
					},
				),
			)
		);
		$result = self::gated( $args + $token );
		AB_MCP_Test_Seam::restore_all();
		// The unique key refuses the insert; the snapshot of the transaction
		// does not show the row of the other connection, so the refusal is
		// the one of the database: all taken back.
		self::assertStringContainsString( 'the database took back all of it', self::refused( $result, 'ab_mcp_sync_pairs_confirm_failed' ) );
		self::assertSame( array( 'plugin:ab-it-inaktiv' ), array_keys( self::pairs() ), 'The pair of the other request only.' );
		self::assertSame( hash( 'sha256', 'other' ), self::pairs()['plugin:ab-it-inaktiv']['base_live'], 'Not overwritten.' );
	}

	/** A stand-in of the calls of the pairs whose reading of a pair by its id after an insert fails (Lesefehler). */
	private static function read_back_fails(): object {
		return new class() {
			private $real;
			private $inserted = false;
			public function __construct() {
				$this->real = new AB_MCP_Sync_Pairs_Calls();
			}
			public function row( $sql ) {
				if ( $this->inserted && 0 === strpos( (string) $sql, 'SELECT * FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE id = ' ) ) {
					return false;
				}
				return $this->real->row( $sql );
			}
			public function update_row( $sql ) {
				return $this->real->update_row( $sql );
			}
			public function insert_row( $table, array $row, array $formats ) {
				$this->inserted = true;
				return $this->real->insert_row( $table, $row, $formats );
			}
		};
	}

	public function testAPairOfAUnitWhoseRowCannotBeReadBackAfterItsInsertIsTakenBack(): void {
		$line = self::line( 'plugin', 'shop' );
		$op   = self::op( 'plugin', 'shop', array( 'files' => 'push', 'activation' => 'push' ), $line, $line );
		$rows = static fn(): int => count( AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );
		// V5 for units: the insert and the reading back in one transaction.
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Pairs::class, 'naht_sync_pairs', self::read_back_fails() );
		$result = AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $op, AB_MCP_Sync_Unit_Proof::pushed( array( 'files' ) ) );
		AB_MCP_Test_Seam::restore_all();
		self::refused( $result, 'ab_mcp_staging_db_error' );
		self::assertSame( 0, $rows(), 'Taken back: no pair the caller was told is not there.' );
		// A reading of the unique keys that fails (Lesefehler) writes nothing.
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Pairs::class, 'naht_sync_pairs', AB_MCP_Sync_Pairs_Calls::class, array( array( 'method' => 'row', 'nth' => 1, 'kind' => 'return', 'value' => false ) ) );
		$result = AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $op, AB_MCP_Sync_Unit_Proof::pushed( array( 'files' ) ) );
		AB_MCP_Test_Seam::restore_all();
		self::refused( $result, 'ab_mcp_staging_db_error' );
		self::assertSame( 0, $rows() );
		// A new alignment whose row changed in between (no row changed).
		self::assertIsArray( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $op, AB_MCP_Sync_Unit_Proof::pushed( array( 'files' ) ) ) );
		global $wpdb;
		$wpdb->query( "UPDATE " . AB_MCP_Sync_Schema::table( 'pairs' ) . " SET state = 'unclear'" ); // phpcs:ignore WordPress.DB
		$pair    = AB_MCP_Sync_Pairs::page_source( 'src-2b' )['pairs'][0];
		$realign = self::op( 'plugin', 'shop', array( 'files' => 'equal', 'activation' => 'equal' ), $line, $line, array( 'pair_id' => (int) $pair['id'], 'expected' => AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ) ) ) );
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Pairs::class, 'naht_sync_pairs', AB_MCP_Sync_Pairs_Calls::class, array( array( 'method' => 'update_row', 'kind' => 'return', 'value' => 0 ) ) );
		self::refused( self::set( $realign ), 'ab_mcp_staging_pair_changed' );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( 'unclear', AB_MCP_Sync_Pairs::get( (int) $pair['id'] )['state'] );
	}

	public function testAnEndOfTheProcessOrItsTimeLimitAtTheBaseOfAUnitKeepsNothingHalf(): void {
		$line  = self::line( 'plugin', 'shop' );
		$op    = self::op( 'plugin', 'shop', array( 'files' => 'push', 'activation' => 'push' ), $line, $line );
		$probe = __DIR__ . '/support/unit-base-probe.php';
		$plan  = static function ( array $fault ) use ( $op, $probe ): array {
			return array(
				'require' => array( $probe ),
				'seams'   => array(
					array(
						'owner'    => 'AB_MCP_Sync_Pairs',
						'property' => 'naht_sync_pairs',
						'real'     => 'AB_MCP_Sync_Pairs_Calls',
						'faults'   => array( array( 'method' => 'insert_row', 'kind' => 'abort', 'point' => 'insert-unit-base' ) + $fault ),
					),
				),
				'calls'   => array(
					array(
						'call' => 'ab_it_unit_base_probe',
						'args' => array( 'src-2b', $op, array( 'files' ) ),
					),
				),
			);
		};
		$cases = array(
			'exit before the insert (no shutdown)' => array( 'mode' => 'no_shutdown' ),
			'exit before the insert (shutdown)'    => array( 'mode' => 'shutdown' ),
			'the time limit of PHP at the insert'  => array( 'mode' => 'timeout' ),
			'exit after the insert (no shutdown)'  => array( 'mode' => 'no_shutdown', 'when' => 'after' ),
			'exit after the insert (shutdown)'     => array( 'mode' => 'shutdown', 'when' => 'after' ),
		);
		foreach ( $cases as $case => $fault ) {
			AB_MCP_IT_Process::abort( 'a', $plan( $fault ) );
			self::assertSame( array(), AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ), $case . ': no pair, also after the insert (its transaction not committed).' );
		}
		$run = AB_MCP_IT_Process::calls( 'a', $plan( array( 'mode' => 'no_shutdown', 'nth' => 99 ) ) );
		self::assertSame( 'written', $run['results']['call0'] );
		self::assertCount( 1, AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );
	}

	/* ---------------------- faults of the proposal and of its environment */

	/** A stand-in of the calls of a proposal: the environment of the staging site as the case changes it, the rest real. */
	private static function environment_as( callable $change ): object {
		return new class( $change ) {
			private $real;
			private $change;
			public function __construct( callable $change ) {
				$this->real   = new AB_MCP_Sync_Proposal_Calls();
				$this->change = $change;
			}
			public function __call( $method, array $args ) {
				return $this->real->$method( ...$args );
			}
			public function open( $area, array $meta ) {
				return $this->real->open( $area, $meta );
			}
			public function remove( $area, $gid ) {
				return $this->real->remove( $area, $gid );
			}
			public function groups( $area ) {
				return $this->real->groups( $area );
			}
			public function files( $area, $gid ) {
				return $this->real->files( $area, $gid );
			}
			public function get( $area, $gid, $name, $sha ) {
				return $this->real->get( $area, $gid, $name, $sha );
			}
			public function put( $area, $gid, $name, $text ) {
				return $this->real->put( $area, $gid, $name, $text );
			}
			public function live_environment( array $found, $start, WP_Error $concurrent ) {
				return $this->real->live_environment( $found, $start, $concurrent );
			}
			public function environment( array $ids ) {
				$env = $this->real->environment( $ids );
				return is_array( $env ) ? call_user_func( $this->change, $env ) : $env;
			}
		};
	}

	public function testAFaultOfTheFilesOfAProposalOrOfTheReadingOfTheEnvironmentPairsNoUnit(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		$packages    = self::staged( 'inventory' );
		$environment = self::staged( 'env' );
		$begin       = array(
			'action'      => 'propose',
			'packages'    => $packages,
			'environment' => $environment,
		);
		$seam        = static function ( array $faults ): void {
			AB_MCP_Test_Seam::restore_all();
			AB_MCP_Test_Seam::install( AB_MCP_Sync_Proposal::class, 'naht_sync_proposal', AB_MCP_Sync_Proposal_Calls::class, $faults );
		};
		// The series of the staging site not read (Rückgabe of an error, Throwable).
		$seam( array( array( 'method' => 'environment', 'kind' => 'return', 'value' => new WP_Error( 'ab_mcp_sync_report_environment_series', 'not one series' ) ) ) );
		self::refused( self::here( $begin ), 'ab_mcp_sync_report_environment_series' );
		$seam( array( array( 'method' => 'environment', 'kind' => 'throw', 'message' => 'packages gone' ) ) );
		try {
			self::here( $begin );
			self::fail( 'The Throwable leaves the call.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'packages gone', $e->getMessage() );
		}
		// The head not written (Rückgabe false): nothing kept as done.
		$seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'return', 'value' => false ) ) );
		self::refused( self::here( $begin ), 'ab_mcp_sync_pairs_unwritten' );
		AB_MCP_Test_Seam::restore_all();
		// The environment of this site throws in a call: the next call goes on.
		$seam( array( array( 'method' => 'live_environment', 'nth' => 1, 'kind' => 'throw', 'message' => 'env gone' ) ) );
		$first = null;
		try {
			self::here( $begin );
		} catch ( RuntimeException $e ) {
			$first = $e->getMessage();
		}
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( 'env gone', $first );
		$groups = array_values( array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'proposal' === $g['label'] ) );
		self::assertCount( 1, $groups );
		$answer = self::here( array( 'action' => 'propose', 'proposal_id' => $groups[0]['gid'] ) );
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::here( array( 'action' => 'propose', 'proposal_id' => $groups[0]['gid'] ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		self::assertGreaterThan( 0, $answer['units']['entries'] );
		// A file of the proposal read cut short (halb gelesen): no page, no confirmation.
		$seam( array( array( 'method' => 'get', 'kind' => 'half', 'keep' => 10 ) ) );
		self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) ), 'ab_mcp_sync_pairs_parts' );
		self::refused( self::gated( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'categories' => array( 'equal' ) ) ), 'ab_mcp_sync_pairs_parts' );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array(), self::pairs() );
		// The environment of this site not read in one call (Zeitlimit of the
		// budget): the answer says how far, the next calls go on.
		$t                      = 0.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
			$t += 1000.0;
			return $t;
		};
		$slow = self::here( $begin );
		AB_MCP_Sync_Env::$clock = null;
		self::assertSame( array( false, 0 ), array( $slow['done'], $slow['environment']['parts'] ), 'Not read: no time left in the call.' );
		$done = self::here( array( 'action' => 'propose', 'proposal_id' => $slow['proposal_id'] ) );
		self::assertTrue( $done['done'] );
		// A unit of the staging site not read whole there (its line cut):
		// not compared, never proposed.
		AB_MCP_Test_Seam::install(
			AB_MCP_Sync_Proposal::class,
			'naht_sync_proposal',
			self::environment_as(
				static function ( array $env ): array {
					foreach ( $env['lines'] as $i => $line ) {
						if ( 'plugin' === ( $line['env'] ?? null ) && 'ab-it-aktiv' === ( $line['ref'] ?? null ) ) {
							$env['lines'][ $i ] = array(
								'complete' => false,
								'hash'     => null,
								'cause'    => 'changed',
							) + $line;
						}
					}
					return $env;
				}
			)
		);
		$cut = self::proposal( $packages, $environment );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( 'not_compared', $cut['units']['plugin:ab-it-aktiv']['category'] );
		self::assertStringContainsString( 'could not be read whole on the staging site', $cut['units']['plugin:ab-it-aktiv']['reason'] );
		self::assertSame( 'equal', $cut['units']['plugin:ab-it-inaktiv']['category'] );
	}

	public function testAnEndOfTheProcessWhileTheProposalOfUnitsIsStoredLeavesItToTheNextCall(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		$t                      = 0.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
			$t += 1000.0;
			return $t;
		};
		$begun = self::here(
			array(
				'action'      => 'propose',
				'packages'    => self::staged( 'inventory' ),
				'environment' => self::staged( 'env' ),
			)
		);
		AB_MCP_Sync_Env::$clock = null;
		self::assertFalse( $begun['done'], 'The inventory read, the environment of this site not yet.' );
		$plan = static function ( array $fault ) use ( $begun ): array {
			return array(
				'require' => array( AB_MCP_DIR . 'includes/sync/class-sync-proposal.php' ),
				'seams'   => array(
					array(
						'owner'    => 'AB_MCP_Sync_Proposal',
						'property' => 'naht_sync_proposal',
						'real'     => 'AB_MCP_Sync_Proposal_Calls',
						// The first file this call writes through the proposal:
						// its entries, after the environment of this site.
						'faults'   => array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'abort', 'point' => 'store-entries' ) + $fault ),
					),
				),
				'calls'   => array(
					array( 'call' => 'wp_set_current_user', 'args' => array( self::admin() ) ),
					array(
						'call' => 'AB_MCP_Sync_Proposal::propose',
						'args' => array( array( 'proposal_id' => $begun['proposal_id'] ) ),
					),
				),
			);
		};
		foreach ( array( 'no_shutdown', 'shutdown', 'timeout' ) as $mode ) {
			AB_MCP_IT_Process::abort( 'a', $plan( array( 'mode' => $mode ) ) );
		}
		$answer = self::here( array( 'action' => 'propose', 'proposal_id' => $begun['proposal_id'] ) );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		self::assertTrue( $answer['done'], 'Worked out by the next call.' );
		self::assertGreaterThan( 0, $answer['units']['entries'] );
		self::assertSame( array(), self::pairs() );
	}

	/* ------------------------------------------------------------- token */

	public function testTheTokenBindsTheUnitItsPartsTheirModesAndValues(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$answer  = self::proposal();
		$n       = $answer['units']['plugin:ab-it-inaktiv']['n'];
		$args    = static fn( string $mode ): array => array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'units'       => array( array( 'entry' => $n, 'part' => 'files', 'mode' => $mode ) ),
		);
		$preview = self::gated( $args( 'live_base' ) );
		$token   = array( 'confirm_token' => $preview['confirm_token'] );
		// Another mode than the preview: refused.
		self::refused( self::gated( $args( 'keep_apart' ) + $token ), 'ab_mcp_confirm_mismatch' );
		// The state of the plan throws while the gate works it out: nothing runs.
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Proposal::class, 'naht_sync_proposal', AB_MCP_Sync_Proposal_Calls::class, array( array( 'method' => 'groups', 'kind' => 'throw', 'message' => 'store gone' ) ) );
		self::refused( self::gated( $args( 'live_base' ) + $token ), 'ab_mcp_internal' );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array(), self::pairs() );
		// The unit paired by another request in between: the token covers it no more.
		$other = \AB_MCP_IT_Pair_Rows::put(
			array(
				'source_id'    => self::source(),
				'kind'         => 'plugin',
				'live_ref'     => 'ab-it-inaktiv',
				'staging_ref'  => 'ab-it-inaktiv',
				'canon'        => AB_MCP_Sync_Canon::VERSION,
				'base_live'    => hash( 'sha256', 'x' ),
				'base_staging' => hash( 'sha256', 'x' ),
				'base_origin'  => 'proposal',
			)
		);
		self::assertStringContainsString( 'It was paired meanwhile', self::refused( self::gated( $args( 'live_base' ) + $token ), 'ab_mcp_sync_pairs_nothing' ) );
		$base_live = $other['base_live'];
		global $wpdb;
		$wpdb->delete( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'id' => (int) $other['id'] ) ); // phpcs:ignore WordPress.DB
		// This site changes the unit after the proposal (an update here): the
		// token still holds the values the proposal read whole; the report then
		// shows the change here and locks the takeover (Z3).
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.0.0' );
		file_put_contents( AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' ) . '/neu.php', '<?php // here' );
		$this->made[] = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' ) . '/neu.php';
		$done         = self::gated( $args( 'live_base' ) + $token );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$pair = self::pairs()['plugin:ab-it-inaktiv'];
		self::assertNotSame( $base_live, $pair['base_live'] );
		$env = self::report();
		self::assertSame( 'conflict', $env['plugin:ab-it-inaktiv']['parts']['files'], 'Changed here since the base, and on staging: locked.' );
		self::assertContains( 'code:live_changed', array_column( $env['plugin:ab-it-inaktiv']['locks'], 'key' ) );
	}

	/* -------------------------------- a new alignment through the proposal */

	public function testAnUnclearPairOfAUnitIsAlignedAnewThroughTheProposalPartByPart(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$fields = array(
			'source_id'    => self::source(),
			'kind'         => 'plugin',
			'canon'        => AB_MCP_Sync_Canon::VERSION,
			'base_live'    => hash( 'sha256', 'old' ),
			'base_staging' => hash( 'sha256', 'old' ),
			'base_origin'  => 'push',
			'base_extra'   => array(
				'kept'  => 1,
				'parts' => array(
					'activation' => array(
						'live'    => hash( 'sha256', 'old-a' ),
						'staging' => hash( 'sha256', 'old-a' ),
					),
				),
			),
		);
		// Two pairs a run left unclear and a rest: one unit the same on both
		// sides, one whose files differ, one on neither site.
		$same  = \AB_MCP_IT_Pair_Rows::put( array( 'state' => 'unclear', 'live_ref' => 'ab-it-zwei', 'staging_ref' => 'ab-it-zwei' ) + $fields );
		$diff  = \AB_MCP_IT_Pair_Rows::put( array( 'state' => 'residual', 'live_ref' => 'ab-it-inaktiv', 'staging_ref' => 'ab-it-inaktiv' ) + $fields );
		$gone  = \AB_MCP_IT_Pair_Rows::put( array( 'state' => 'unclear', 'live_ref' => 'ab-it-weg', 'staging_ref' => 'ab-it-weg' ) + $fields );
		$answer = self::proposal();
		$realign = array();
		foreach ( $answer['all'] as $entry ) {
			if ( 'realign' === $entry['category'] ) {
				$realign[ $entry['pair']['id'] ] = $entry;
			}
		}
		ksort( $realign );
		self::assertSame( array( (int) $same['id'], (int) $diff['id'], (int) $gone['id'] ), array_keys( $realign ), 'One entry each, never as an object of the proposal (V4).' );
		foreach ( $realign as $entry ) {
			self::assertTrue( $entry['unit'] );
		}
		self::assertSame( array( 'active', array( 'activation' => 'equal', 'files' => 'equal' ) ), array( $realign[ (int) $same['id'] ]['confirm'], $realign[ (int) $same['id'] ]['parts'] ) );
		self::assertSame( array( 'modes', array( 'files' ) ), array( $realign[ (int) $diff['id'] ]['confirm'], $realign[ (int) $diff['id'] ]['differs'] ) );
		self::assertNull( $realign[ (int) $gone['id'] ]['confirm'] );
		self::assertStringContainsString( 'on neither site any more', $realign[ (int) $gone['id'] ]['reason'] );
		$c = static fn( array $more ): array => array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
		) + $more;
		self::assertStringContainsString( 'cannot be aligned anew', self::refused( self::gated( $c( array( 'select' => array( $realign[ (int) $gone['id'] ]['n'] ) ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		self::assertStringContainsString( 'in units', self::refused( self::gated( $c( array( 'select' => array( $realign[ (int) $diff['id'] ]['n'] ) ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		list( $preview, $done ) = self::confirmed(
			$c(
				array(
					'select' => array( $realign[ (int) $same['id'] ]['n'] ),
					'units'  => array( array( 'entry' => $realign[ (int) $diff['id'] ]['n'], 'part' => 'files', 'mode' => 'live_base' ) ),
				)
			)
		);
		self::assertSame( array( 'aligned' => 2 ), $preview['preview']['counts'] );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$here  = self::lines_here();
		$pairs = self::pairs();
		foreach ( array( 'plugin:ab-it-zwei', 'plugin:ab-it-inaktiv' ) as $key ) {
			$pair = $pairs[ $key ];
			$act  = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $here[ $key ] );
			self::assertSame( array( 'active', 'proposal', $here[ $key ]['hash'], $here[ $key ]['hash'] ), array( $pair['state'], $pair['base_origin'], $pair['base_live'], $pair['base_staging'] ), $key );
			self::assertSame( array( 'kept' => 1, 'parts' => array( 'activation' => array( 'live' => $act, 'staging' => $act ) ) ), $pair['base_extra'], $key . ': every part anew, the rest kept.' );
		}
		self::assertSame( 'unclear', $pairs['plugin:ab-it-weg']['state'] );
		$env = self::report();
		self::assertSame( 'changed_on_staging', $env['plugin:ab-it-inaktiv']['result'] );
		self::assertSame( 'equal', $env['plugin:ab-it-zwei']['result'] );
	}

	public function testTheEnvironmentOfAProposalIsOneSeriesOfItsOwn(): void {
		self::pair();
		$packages = self::staged( 'inventory' );
		$env      = self::staged( 'env' );
		$begin    = static fn( $environment ): array => array(
			'action'      => 'propose',
			'packages'    => $packages,
			'environment' => $environment,
		);
		foreach ( array(
			'no list'           => array( 'a' => $env[0] ),
			'not an array'      => $env[0],
			'a package twice'   => array( $env[0], $env[0] ),
			'of the inventory'  => array( $packages[0] ),
			'more than 50'      => array_map( static fn( $i ) => str_repeat( dechex( $i % 16 ), 32 ), range( 0, 50 ) ),
		) as $case => $environment ) {
			self::assertStringContainsString( 'environment names the package_ids', self::refused( self::here( $begin( $environment ) ), 'ab_mcp_sync_pairs_environment' ), $case );
		}
		// A package of the inventory is no package of the environment: the
		// series of the staging site checked as a report checks it.
		self::refused( self::here( $begin( array( str_repeat( 'f', 32 ) ) ) ), 'ab_mcp_sync_import_unknown' );
		$without = self::here( array( 'action' => 'propose', 'packages' => $packages ) );
		while ( false === $without['done'] ) {
			$without = self::here( array( 'action' => 'propose', 'proposal_id' => $without['proposal_id'] ) );
		}
		self::assertSame( 0, $without['units']['entries'], 'Without environment, no unit is proposed.' );
		self::assertSame( array(), array_values( array_filter( $without['entries'], static fn( $e ) => ! empty( $e['unit'] ) ) ) );
	}
}
