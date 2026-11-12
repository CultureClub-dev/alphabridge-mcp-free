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
 * differ already, confirmed part by part (Frage F: b live_base, c
 * keep_apart, a a part without a mode stays open; Behebung 1, A1: a second
 * part later alone, one decision changed alone, every other base byte for
 * byte), a unit on one side only (no pair, «only live» apart from a removal
 * on staging), the units that are not paired (not read whole, locked, held,
 * a name too long), every clause of AB_MCP_Sync_Pairs::set_unit_base()
 * (Z44), what confirms a base made only from what happened (A2), no way past
 * it, a unit back only to the state its journal names (A3; a step that gives
 * no first base: SyncManifestTest, A4), and the base with origin push (F5,
 * the contract only, by the journal of a step; B5: a part not written kept
 * apart from one written and checked; branches 5 and 6 bring the writing).
 *
 * WIRKUNGEN NACH AUSSEN × FEHLERARTEN (plan 8.3, branch 2b):
 * Rows of the pairs (seam naht_sync_pairs: row, update_row, insert_row):
 * - return false at the insert, a read that fails (Lesefehler) and no row
 *   changed at the new alignment, Throwable at the insert, a writer in
 *   between (gleichzeitiger Schreiber, before the insert and before the
 *   update): testAFaultOfTheRowsOfThePairsWhileUnitsAreConfirmedKeepsNothing,
 *   testAPairOfAUnitWhoseRowCannotBeReadBackAfterItsInsertIsTakenBack;
 * - exit/Fatal and the time limit of PHP at the insert, before and after it,
 *   in a process of its own: testAnEndOfTheProcessOrItsTimeLimitAtTheBaseOfAUnitKeepsNothingHalf;
 * - halb geschrieben: does not apply, a pair is one INSERT or one UPDATE of
 *   one row (all columns or none), and a confirmation is one transaction.
 * The files of the proposals and the reading of the environment of both
 * sides (seam naht_sync_proposal: put, get, files, groups, open, remove,
 * environment, live_environment):
 * - return false and Throwable at a write (the head, and the first file of
 *   the call that stores the entries), a file of its name claimed there
 *   that does not read back against its SHA-256, an error at a read, a read
 *   that fails or gives other bytes (Lesefehler, halb gelesen), Throwable,
 *   the series of the staging site refused, the environment of this site
 *   not read to its end in one call (Zeitlimit of the budget):
 *   testAFaultOfTheFilesOfAProposalOrOfTheReadingOfTheEnvironmentPairsNoUnit;
 * - exit/Fatal and the time limit of PHP while the proposal stores its
 *   entries, in a process of its own, before its first file, after it, and
 *   before and after its index (Abbruch nach schon abgelegten Teilen): the
 *   next call works it out again, meets a file there by its name and
 *   SHA-256, goes on and comes to the same proposal:
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
 *   Throwable while the state is worked out, the tool past the gate without
 *   a check (A2): testTheTokenBindsTheUnitItsPartsTheirModesAndValues; the
 *   plan written is the one the token was checked against (V1):
 *   SyncProposalTest::testTheConfirmationWritesThePlanItsTokenWasCheckedAgainst.
 * The journal of a step (the proof of origin push, the state a unit goes
 * back to): read only; a step not done, of another run, pair or source, or
 * without a unit proves nothing: testWhatConfirmsABaseIsMadeOnlyFromWhatHappened,
 * testAUnitGoesBackOnlyToTheStateItsJournalNames. Its faults come with
 * branch 5, which writes it.
 * Hooks of others: none new (plan 8.3).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_Confirm_Token;
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
		self::assertStringContainsString( 'A unit (a plugin, a theme, the theme that runs) takes a base for each part chosen', $preview['preview']['effect'] );
		self::assertArrayNotHasKey( 'open', $ops['plugin:ab-it-aktiv'], 'Every part chosen.' );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertTrue( $done['done'] );

		$pairs = self::pairs();
		$pair  = $pairs['plugin:ab-it-aktiv'];
		self::assertSame( array( 'active', 'proposal', 'ab-it-aktiv', AB_MCP_Sync_Canon::VERSION ), array( $pair['state'], $pair['base_origin'], $pair['staging_ref'], $pair['canon'] ) );
		$act = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $here['plugin:ab-it-aktiv'] );
		self::assertSame( array( $here['plugin:ab-it-aktiv']['hash'], $here['plugin:ab-it-aktiv']['hash'] ), array( $pair['base_live'], $pair['base_staging'] ), 'The files of the manifests read whole.' );
		self::assertSame( array( 'live' => $act, 'staging' => $act ), $pair['base_extra']['parts']['activation'], 'Which main files run.' );
		self::assertSame( array( 'activation' => 'equal', 'files' => 'equal' ), $pair['base_extra']['modes'] );
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

	public function testAUnitThatDiffersIsConfirmedPartByPartWithTheModeChosenForEachPartThatDiffers(): void {
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
		self::assertStringContainsString( 'part by part', $inakt['note'] );
		// C8: each list of paths with how many it holds, and the way to all.
		self::assertGreaterThanOrEqual( count( $inakt['paths']['differs'] ), $inakt['paths']['differs_count'] );
		self::assertGreaterThan( 0, $inakt['paths']['differs_count'] );
		self::assertStringContainsString( 'wp_site_diff with environment) shows every path', $inakt['paths']['note'] );

		$c = static fn( array $more ): array => array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
		) + $more;

		// a: a part that differs takes no base without a mode; an entry whose
		// every part differs gives select nothing to confirm.
		self::assertStringContainsString( 'Name in units', self::refused( self::gated( $c( array( 'select' => array( $einz['n'] ) ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		// By the category, the objects of other content come, a unit not.
		$by_category = self::gated( $c( array( 'categories' => array( 'differs' ) ) ) );
		self::assertSame( array(), array_values( array_filter( $by_category['preview']['pairs'], static fn( $op ) => 'unit' === $op['op'] ) ) );
		$skipped = array_column( $by_category['preview']['skipped'], 'reason', 'entry' );
		foreach ( array( $inakt, $einz, $basis ) as $unit ) {
			self::assertStringContainsString( 'is confirmed only by entry', $skipped[ $unit['n'] ], $unit['ref'] );
		}
		// A mode for a part that is the same, equal named as a mode, a part twice.
		self::assertStringContainsString( 'does not fit the part activation', self::refused( self::gated( $c( array( 'units' => array( array( 'entry' => $inakt['n'], 'part' => 'activation', 'mode' => 'live_base' ) ) ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		self::assertStringContainsString( 'does not fit the part files', self::refused( self::gated( $c( array( 'units' => array( array( 'entry' => $units['plugin:ab-it-zwei']['n'], 'part' => 'files', 'mode' => 'live_base' ) ) ) ) ), 'ab_mcp_sync_pairs_selection' ), 'A unit that is equal takes no mode.' );
		self::assertSame( 'ab_mcp_sync_pairs_selection', self::gated( $c( array( 'units' => array( array( 'entry' => $inakt['n'], 'part' => 'files', 'mode' => 'equal' ) ) ) ) )->get_error_code(), 'equal is no mode a person chooses.' );
		self::assertSame( 'ab_mcp_sync_pairs_selection', self::gated( $c( array( 'units' => array( array( 'entry' => $inakt['n'], 'part' => 'files', 'mode' => 'live_base' ), array( 'entry' => $inakt['n'], 'part' => 'files', 'mode' => 'keep_apart' ) ) ) ) )->get_error_code(), 'Each part of an entry once.' );
		self::assertSame( array(), self::pairs(), 'Nothing paired.' );

		// A1, first: the parts the person decides now; of einz and inakt their
		// files only, zwei (equal) whole.
		$zwei                   = $units['plugin:ab-it-zwei'];
		$args                   = $c(
			array(
				'select' => array( $basis['n'], $zwei['n'] ),
				'units'  => array(
					array( 'entry' => $inakt['n'], 'part' => 'files', 'mode' => 'live_base' ),
					array( 'entry' => $einz['n'], 'part' => 'files', 'mode' => 'keep_apart' ),
					array( 'entry' => $basis['n'], 'part' => 'activation', 'mode' => 'keep_apart' ),
				),
			)
		);
		list( $preview, $done ) = self::confirmed( $args );
		$named                  = array();
		foreach ( $preview['preview']['pairs'] as $op ) {
			$named[ $op['ref'] ] = $op;
		}
		self::assertSame( array( array( 'files' => 'live_base' ), array( 'activation' ) ), array( $named['ab-it-inaktiv']['parts'], $named['ab-it-inaktiv']['open'] ), 'A part the same, not chosen: open as well.' );
		self::assertSame( array( array( 'files' => 'keep_apart' ), array( 'activation' ) ), array( $named['ab-it-einzeln.php']['parts'], $named['ab-it-einzeln.php']['open'] ), 'The part not chosen named as open.' );
		self::assertSame( array( 'files' => 'equal', 'activation' => 'equal' ), $named['ab-it-zwei']['parts'] );
		self::assertSame( array( 'files' => 'equal', 'activation' => 'keep_apart' ), $named['ab-it-basis']['parts'] );
		foreach ( array( 'does not prove that the two sites ever had this version in common', 'can no longer be told from these bases', 'belongs to this base', 'put it into the backup with the old folder or replace it with what staging has', 'still locks a takeover (code:live_changed)', 'no older version replaces a newer one' ) as $said ) {
			self::assertStringContainsString( $said, $named['ab-it-inaktiv']['effect'], 'Frage F, b: ' . $said );
		}
		self::assertStringContainsString( 'A later change on either side counts again', $named['ab-it-einzeln.php']['effect'], 'Frage F, c.' );
		self::assertStringContainsString( 'A part not chosen stays as it is', $preview['preview']['effect'] );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertStringContainsString( 'does not prove', implode( ' ', array_column( $done['pairs'], 'effect' ) ), 'The answer says it again.' );

		$here  = self::lines_here();
		$there = self::lines_there();
		$pairs = self::pairs();
		$p     = $pairs['plugin:ab-it-inaktiv'];
		self::assertSame( array( $here['plugin:ab-it-inaktiv']['hash'], $here['plugin:ab-it-inaktiv']['hash'] ), array( $p['base_live'], $p['base_staging'] ), 'b: the state of this site is the base of both sides.' );
		$p = $pairs['plugin:ab-it-einzeln.php'];
		self::assertSame( array( $here['plugin:ab-it-einzeln.php']['hash'], $there['plugin:ab-it-einzeln.php']['hash'] ), array( $p['base_live'], $p['base_staging'] ), 'c: each side its own base.' );
		self::assertNull( AB_MCP_Sync_Pairs::part_bases( $p, 'activation' ), 'a: the activation not chosen, without a base.' );
		self::assertSame( array( 'files' => 'keep_apart' ), $p['base_extra']['modes'] );
		$act_here  = static fn( string $key ): string => AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $here[ $key ] );
		$act_there = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $there['plugin:ab-it-basis'] );
		self::assertSame( array( 'live' => $act_here( 'plugin:ab-it-basis' ), 'staging' => $act_there ), $pairs['plugin:ab-it-basis']['base_extra']['parts']['activation'], 'c for the other part of a unit.' );

		$env = self::report();
		self::assertSame( array( 'changed_on_staging', array( 'activation' => 'open', 'files' => 'changed_on_staging' ) ), array( $env['plugin:ab-it-inaktiv']['result'], $env['plugin:ab-it-inaktiv']['parts'] ), 'b: the difference is a change on staging.' );
		self::assertSame( array( 'open', array( 'activation' => 'open', 'files' => 'kept_apart' ), 'none' ), array( $env['plugin:ab-it-einzeln.php']['result'], $env['plugin:ab-it-einzeln.php']['parts'], $env['plugin:ab-it-einzeln.php']['action'] ), 'a: open, nothing taken over.' );
		self::assertStringContainsString( 'no base yet', $env['plugin:ab-it-einzeln.php']['words'] );
		self::assertSame( array( 'kept_apart', array( 'activation' => 'kept_apart', 'files' => 'equal' ) ), array( $env['plugin:ab-it-basis']['result'], $env['plugin:ab-it-basis']['parts'] ), 'c: kept apart, nothing to take over.' );
		self::assertSame( 'none', $env['plugin:ab-it-basis']['action'] );

		// A1, second: the other part of einz, later and alone; the files of it
		// and every other pair as they are, byte for byte.
		$again = self::proposal();
		$e     = $again['units']['plugin:ab-it-einzeln.php'];
		self::assertSame( array( 'realign', 'modes', (int) $p['id'], array( 'activation' => 'open', 'files' => 'kept_apart' ) ), array( $e['category'], $e['confirm'], $e['pair']['id'], $e['states'] ) );
		$i     = $again['units']['plugin:ab-it-inaktiv'];
		self::assertSame( array( 'realign', array( 'activation' => 'open', 'files' => 'changed_on_staging' ) ), array( $i['category'], $i['states'] ), 'Its open part waits; live_base can change.' );
		$snap   = static fn( string $key ): string => (string) AB_MCP_Sync_Pairs::snapshot( (int) self::pairs()[ $key ]['id'] );
		$before = array(
			'basis' => $snap( 'plugin:ab-it-basis' ),
			'zwei'  => $snap( 'plugin:ab-it-zwei' ),
		);
		$inakt_before           = self::pairs()['plugin:ab-it-inaktiv'];
		list( $preview, $done ) = self::confirmed(
			array(
				'action'      => 'confirm',
				'proposal_id' => $again['proposal_id'],
				'select'      => array( $i['n'] ),
				'units'       => array( array( 'entry' => $e['n'], 'part' => 'activation', 'mode' => 'live_base' ) ),
			)
		);
		$named = array();
		foreach ( $preview['preview']['pairs'] as $op ) {
			$named[ $op['ref'] ] = $op;
		}
		self::assertSame( array( array( 'activation' => 'live_base' ), array( 'files' ) ), array( $named['ab-it-einzeln.php']['parts'], $named['ab-it-einzeln.php']['unchanged'] ), 'The other part named as unchanged.' );
		self::assertSame( array( array( 'activation' => 'equal' ), array( 'files' ) ), array( $named['ab-it-inaktiv']['parts'], $named['ab-it-inaktiv']['unchanged'] ), 'select: the open part that is the same, the decision on the files untouched.' );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$inakt_now = self::pairs()['plugin:ab-it-inaktiv'];
		self::assertSame( array( $inakt_before['base_live'], $inakt_before['base_staging'] ), array( $inakt_now['base_live'], $inakt_now['base_staging'] ) );
		self::assertSame( array( 'live' => $act_here( 'plugin:ab-it-inaktiv' ), 'staging' => $act_here( 'plugin:ab-it-inaktiv' ) ), $inakt_now['base_extra']['parts']['activation'] );
		self::assertSame( array( 'activation' => 'equal', 'files' => 'live_base' ), $inakt_now['base_extra']['modes'] );
		$now = self::pairs()['plugin:ab-it-einzeln.php'];
		self::assertSame( array( $p['base_live'], $p['base_staging'] ), array( $now['base_live'], $now['base_staging'] ), 'The files as they were.' );
		self::assertSame( array( 'live' => $act_here( 'plugin:ab-it-einzeln.php' ), 'staging' => $act_here( 'plugin:ab-it-einzeln.php' ) ), $now['base_extra']['parts']['activation'] );
		self::assertSame( array( 'activation' => 'live_base', 'files' => 'keep_apart' ), $now['base_extra']['modes'] );
		self::assertSame( $before, array( 'basis' => $snap( 'plugin:ab-it-basis' ), 'zwei' => $snap( 'plugin:ab-it-zwei' ) ), 'The other pairs untouched.' );
		$env = self::report();
		self::assertSame( array( 'activation' => 'changed_on_staging', 'files' => 'kept_apart' ), $env['plugin:ab-it-einzeln.php']['parts'] );

		// A1, third: one decision changed alone, the activation of basis from
		// keep_apart to live_base; the base of its files byte for byte. And zwei
		// updated alike on both sides: its files changed alike, confirmed by
		// select, its activation as it is.
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-zwei', '1.1.0' );
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-zwei', '1.1.0' );
		$third = self::proposal();
		$b     = $third['units']['plugin:ab-it-basis'];
		self::assertSame( array( 'realign', array( 'activation' => 'kept_apart', 'files' => 'equal' ) ), array( $b['category'], $b['states'] ) );
		$z = $third['units']['plugin:ab-it-zwei'];
		self::assertSame( array( 'realign', array( 'activation' => 'equal', 'files' => 'changed_alike' ) ), array( $z['category'], $z['states'] ) );
		$c3 = static fn( array $more ): array => array(
			'action'      => 'confirm',
			'proposal_id' => $third['proposal_id'],
		) + $more;
		self::assertStringContainsString( 'has no part select confirms', self::refused( self::gated( $c3( array( 'select' => array( $b['n'] ) ) ) ), 'ab_mcp_sync_pairs_selection' ), 'select does not touch a decision taken.' );
		$old      = self::pairs()['plugin:ab-it-basis'];
		$old_zwei = self::pairs()['plugin:ab-it-zwei'];
		$other    = array(
			'inakt' => $snap( 'plugin:ab-it-inaktiv' ),
			'einz'  => $snap( 'plugin:ab-it-einzeln.php' ),
		);
		list( , $done ) = self::confirmed(
			$c3(
				array(
					'select' => array( $z['n'] ),
					'units'  => array( array( 'entry' => $b['n'], 'part' => 'activation', 'mode' => 'live_base' ) ),
				)
			)
		);
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$here     = self::lines_here();
		$new_zwei = self::pairs()['plugin:ab-it-zwei'];
		self::assertSame( array( $here['plugin:ab-it-zwei']['hash'], $here['plugin:ab-it-zwei']['hash'] ), array( $new_zwei['base_live'], $new_zwei['base_staging'] ), 'Changed alike: the state now, the base of both sides.' );
		self::assertNotSame( $old_zwei['base_live'], $new_zwei['base_live'] );
		self::assertSame( $old_zwei['base_extra']['parts'], $new_zwei['base_extra']['parts'], 'Its activation as it was.' );
		$new = self::pairs()['plugin:ab-it-basis'];
		self::assertSame( array( $old['base_live'], $old['base_staging'] ), array( $new['base_live'], $new['base_staging'] ), 'The files of basis as they were.' );
		self::assertSame( array( 'live' => $act_here( 'plugin:ab-it-basis' ), 'staging' => $act_here( 'plugin:ab-it-basis' ) ), $new['base_extra']['parts']['activation'] );
		self::assertSame( array( 'activation' => 'live_base', 'files' => 'equal' ), $new['base_extra']['modes'] );
		self::assertSame( $other, array( 'inakt' => $snap( 'plugin:ab-it-inaktiv' ), 'einz' => $snap( 'plugin:ab-it-einzeln.php' ) ) );
		$env = self::report();
		self::assertSame( array( 'activation' => 'changed_on_staging', 'files' => 'equal' ), $env['plugin:ab-it-basis']['parts'], 'The decision changed: a change on staging now.' );
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
			self::assertStringContainsString( 'is no unit that can be paired', self::refused( self::gated( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'units' => array( array( 'entry' => $units[ $key ]['n'], 'part' => 'files', 'mode' => 'live_base' ) ) ) ), 'ab_mcp_sync_pairs_selection' ), $key . ': takes no mode.' );
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
		$op   = self::op( 'plugin', 'ab-it-nur-hier.php', array( 'files' => 'equal', 'activation' => 'equal' ), $line, $line, array( 'source' => self::source() ) );
		$op['lines']['staging'] = null;
		self::assertSame( 'side', self::why( self::set( $op ) ) );
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

	/** An operation of set_unit_base() with the values its lines give in its modes, of the source src-2b unless $more names another. */
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
				'source'   => 'src-2b',
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

	/**
	 * What confirms operations as the gate makes it: a token of
	 * wp_site_pairs checked in this request against a state that holds them
	 * (AB_MCP_Confirm_Token::check(), as gate() calls it), then
	 * AB_MCP_Sync_Unit_Proof::of_confirmation() with the same arguments and
	 * state.
	 */
	private static function confirmation( array $ops, string $tag = 'clause' ): ?AB_MCP_Sync_Unit_Proof {
		$args  = array(
			'action' => 'confirm',
			'tag'    => $tag,
		);
		$state = array( 'ops' => $ops );
		$hash  = hash( 'sha256', AB_MCP_Sync_Canon::encode( $state ) );
		self::assertTrue( AB_MCP_Confirm_Token::check( 'wp_site_pairs', $args, $hash, AB_MCP_Confirm_Token::issue( 'wp_site_pairs', $args, $hash, time() ), time() ) );
		return AB_MCP_Sync_Unit_Proof::of_confirmation( $args, $state );
	}

	/** set_unit_base() of an operation with a confirmation that holds it, for the source it names. */
	private static function set( array $op ) {
		return AB_MCP_Sync_Pairs::set_unit_base( (string) $op['source'], $op, self::confirmation( array( $op ) ) );
	}

	/**
	 * The proof of a step whose journal holds the unit (a closed run of the
	 * source with one step done): the parts it wrote and checked, the parts
	 * it left as this site has them after the write, and the hash of each.
	 */
	private static function step_proof( string $source, string $kind, string $ref, array $written, array $after, array $checked, string $state = 'done' ): ?AB_MCP_Sync_Unit_Proof {
		$step = \AB_MCP_IT_Pair_Rows::journal(
			$source,
			array(
				'unit' => array(
					'kind'             => $kind,
					'ref'              => $ref,
					'written'          => $written,
					'live_after_write' => $after,
					'checked'          => $checked,
				),
			),
			null,
			null,
			$state
		);
		return AB_MCP_Sync_Unit_Proof::of_step( $step['run_id'], $step['seq'] );
	}

	/** The hash of each part of a unit on a line (checked). */
	private static function checked( string $kind, array $line ): array {
		$out = array();
		foreach ( AB_MCP_Sync_Pairs::PARTS[ $kind ] as $part ) {
			$out[ $part ] = AB_MCP_Sync_Decision::part_hash( $kind, $part, $line );
		}
		return $out;
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
			'no part'       => array( array_replace( $good, array( 'parts' => array() ) ), 'parts' ),
			'a part more'   => array( self::op( 'theme', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), self::line( 'theme', 'shop', array( 'hash' => hash( 'sha256', 'v2' ) ) ), self::line( 'theme', 'shop' ) ), 'parts' ),
			'another part'  => array( array_replace( $good, array( 'parts' => array( 'files' => $good['parts']['files'], 'other' => $good['parts']['activation'] ) ) ), 'parts' ),
			'mode of push'  => array( self::op( 'plugin', 'shop', array( 'files' => 'written', 'activation' => 'equal' ), $s, $l ), 'mode' ),
			'after write'   => array( self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'live_after_write' ), $s, $l ), 'mode' ),
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
		// What confirms it holds the very operation, for this source: none, a
		// confirmation of another, of another source, or a step.
		self::assertSame( 'proof', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $good, null ) ) );
		self::assertSame( 'proof', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $good, self::confirmation( array( self::op( 'plugin', 'shop', array( 'files' => 'keep_apart', 'activation' => 'equal' ), $s, $l ) ) ) ) ) );
		self::assertSame( 'proof', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-other', $good, self::confirmation( array( $good ) ) ) ), 'A2: the confirmation of one source is none for another.' );
		$moved = array( 'source' => 'src-other' ) + $good;
		self::assertSame( 'proof', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-other', $moved, self::confirmation( array( $good ) ) ) ), 'A2: nor with the source of the operation changed.' );
		self::assertSame( 'mode', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $good, self::step_proof( 'src-2b', 'plugin', 'shop', array( 'files' ), array( 'activation' ), self::checked( 'plugin', $l ) ) ) ), 'A confirmation of a person is no step.' );
		self::assertSame( 0, $rows() );

		// A pair of one part: the other stays without a base (open; A1).
		$files = self::set( self::op( 'plugin', 'shop', array( 'files' => 'live_base' ), $s, $l ) );
		self::assertIsArray( $files, is_wp_error( $files ) ? $files->get_error_message() : '' );
		self::assertSame( array( $l['hash'], $l['hash'], array( 'modes' => array( 'files' => 'live_base' ) ) ), array( $files['base_live'], $files['base_staging'], $files['base_extra'] ) );
		self::assertNull( AB_MCP_Sync_Pairs::part_bases( $files, 'activation' ), 'The activation open.' );
		$act_only = self::set( self::op( 'plugin', 'cart', array( 'activation' => 'equal' ), self::line( 'plugin', 'cart' ), self::line( 'plugin', 'cart' ) ) );
		self::assertIsArray( $act_only );
		self::assertSame( array( AB_MCP_Sync_Pairs::OPEN, AB_MCP_Sync_Pairs::OPEN ), array( $act_only['base_live'], $act_only['base_staging'] ), 'The files open: no column value is a base.' );
		self::assertNull( AB_MCP_Sync_Pairs::part_bases( $act_only, 'files' ) );
		self::assertNotNull( AB_MCP_Sync_Pairs::part_bases( $act_only, 'activation' ) );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) ); // phpcs:ignore WordPress.DB

		// The pair, each part as its mode says.
		$pair = self::set( $good );
		self::assertIsArray( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		$act = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $l );
		self::assertSame( array( 'active', 'proposal', 'shop', 'shop', $l['hash'], $l['hash'] ), array( $pair['state'], $pair['base_origin'], $pair['live_ref'], $pair['staging_ref'], $pair['base_live'], $pair['base_staging'] ) );
		self::assertSame(
			array(
				'modes' => array( 'activation' => 'equal', 'files' => 'live_base' ),
				'parts' => array( 'activation' => array( 'live' => $act, 'staging' => $act ) ),
			),
			$pair['base_extra'],
			'B5: the mode of each part kept with its base.'
		);
		self::assertSame( 'ab_mcp_staging_pair_conflict', self::set( $good )->get_error_code(), 'One pair a unit.' );
		$c = self::set( self::op( 'theme', 'look', array( 'files' => 'keep_apart' ), self::line( 'theme', 'look', array( 'hash' => hash( 'sha256', 'st' ) ) ), self::line( 'theme', 'look' ) ) );
		self::assertSame( array( hash( 'sha256', 'files-look' ), hash( 'sha256', 'st' ), array( 'modes' => array( 'files' => 'keep_apart' ) ) ), array( $c['base_live'], $c['base_staging'], $c['base_extra'] ), 'c: each side its own base; a theme has one part.' );
		$t = self::set( self::op( 'theme_choice', 'theme_choice', array( 'activation' => 'live_base' ), self::line( 'theme_choice', 'theme_choice', array( 'stylesheet' => 'other' ) ), self::line( 'theme_choice', 'theme_choice' ) ) );
		$h = AB_MCP_Sync_Canon::hash( array( 'stylesheet' => 'kind', 'template' => 'eltern' ) );
		self::assertSame( array( $h, $h ), array( $t['base_live'], $t['base_staging'] ), 'The theme that runs, from both its rows.' );

		// A new alignment of an active pair: the part named anew, every other
		// part as it is, byte for byte (A1).
		$expected = static fn( array $p ): string => AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $p['id'] ) );
		$realign  = self::op( 'plugin', 'shop', array( 'files' => 'keep_apart' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => hash( 'sha256', 'old' ) ) );
		self::assertSame( 'ab_mcp_staging_pair_changed', self::set( $realign )->get_error_code(), 'As the person saw it.' );
		$other = self::op( 'theme', 'look', array( 'files' => 'equal' ), self::line( 'theme', 'look' ), self::line( 'theme', 'look' ), array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $pair ) ) );
		self::assertSame( 'identity', self::why( self::set( $other ) ) );
		$realign = self::op( 'plugin', 'shop', array( 'files' => 'keep_apart' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $pair ) ) );
		$now     = self::set( $realign );
		self::assertIsArray( $now, is_wp_error( $now ) ? $now->get_error_message() : '' );
		self::assertSame( array( 'active', 'proposal', $l['hash'], $s['hash'] ), array( $now['state'], $now['base_origin'], $now['base_live'], $now['base_staging'] ) );
		self::assertSame( $pair['base_extra']['parts'], $now['base_extra']['parts'], 'The base of the activation as it was.' );
		self::assertSame( array( 'activation' => 'equal', 'files' => 'keep_apart' ), $now['base_extra']['modes'] );
		$again = self::set( self::op( 'plugin', 'shop', array( 'activation' => 'equal' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $now ) ) ) );
		self::assertSame( array( $now['base_live'], $now['base_staging'] ), array( $again['base_live'], $again['base_staging'] ), 'The files as they were: the other part named alone.' );

		// A pair unclear or a rest: active again; the parts not named without a
		// base (nothing of it counts as confirmed), the other keys kept.
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'state' => 'unclear', 'canon' => 'canon/0', 'base_extra' => '{"modes":{"activation":"equal","files":"live_base"},"other":1,"parts":{"activation":{"live":"' . str_repeat( 'a', 64 ) . '","staging":"' . str_repeat( 'b', 64 ) . '"}}}' ), array( 'id' => (int) $pair['id'] ) ); // phpcs:ignore WordPress.DB
		$unclear = AB_MCP_Sync_Pairs::get( (int) $pair['id'] );
		$now     = self::set( self::op( 'plugin', 'shop', array( 'files' => 'keep_apart' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $unclear ) ) ) );
		self::assertIsArray( $now, is_wp_error( $now ) ? $now->get_error_message() : '' );
		self::assertSame( array( 'active', 'proposal', $l['hash'], $s['hash'], AB_MCP_Sync_Canon::VERSION ), array( $now['state'], $now['base_origin'], $now['base_live'], $now['base_staging'], $now['canon'] ), 'Active, in the version of the comparison data of now.' );
		self::assertSame( array( 'modes' => array( 'files' => 'keep_apart' ), 'other' => 1 ), $now['base_extra'], 'The activation open, its base and mode gone, the other keys kept.' );
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'state' => 'residual' ), array( 'id' => (int) $pair['id'] ) ); // phpcs:ignore WordPress.DB
		$rest = AB_MCP_Sync_Pairs::get( (int) $pair['id'] );
		$now  = self::set( self::op( 'plugin', 'shop', array( 'activation' => 'equal' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $rest ) ) ) );
		self::assertSame( array( 'active', AB_MCP_Sync_Pairs::OPEN, AB_MCP_Sync_Pairs::OPEN ), array( $now['state'], $now['base_live'], $now['base_staging'] ), 'A rest: the files not named, without a base.' );
		self::assertSame( array( 'modes' => array( 'activation' => 'equal' ), 'other' => 1, 'parts' => array( 'activation' => array( 'live' => $act, 'staging' => $act ) ) ), $now['base_extra'] );
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'state' => 'deleted' ), array( 'id' => (int) $pair['id'] ) ); // phpcs:ignore WordPress.DB
		$gone = AB_MCP_Sync_Pairs::get( (int) $pair['id'] );
		self::assertSame( 'pair_state', self::why( self::set( self::op( 'plugin', 'shop', array( 'files' => 'keep_apart' ), $s, $l, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $gone ) ) ) ) ) );
		$push = self::op( 'plugin', 'shop', array( 'files' => 'written', 'activation' => 'written' ), $s, $s, array( 'pair_id' => (int) $pair['id'], 'expected' => $expected( $gone ) ) );
		self::assertSame( 'written', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $push, self::step_proof( 'src-2b', 'plugin', 'shop', array( 'files', 'activation' ), array(), self::checked( 'plugin', $s ) ) ) ), 'push makes a pair, never aligns one.' );
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

	/* ------------------------------ what confirms a base (Behebung 1, A2) */

	public function testWhatConfirmsABaseIsMadeOnlyFromWhatHappened(): void {
		$s    = self::line( 'plugin', 'shop', array( 'hash' => hash( 'sha256', 'v2' ) ) );
		$l    = self::line( 'plugin', 'shop', array( 'hash' => hash( 'sha256', 'v1' ) ) );
		$good = self::op( 'plugin', 'shop', array( 'files' => 'live_base', 'activation' => 'equal' ), $s, $l );
		$rows = static fn(): int => count( AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );

		// No maker that takes what a caller says.
		foreach ( array( 'confirmed', 'pushed' ) as $maker ) {
			self::assertFalse( method_exists( AB_MCP_Sync_Unit_Proof::class, $maker ), 'A2: no proof from operations a caller names (' . $maker . ').' );
		}
		try {
			$made = new AB_MCP_Sync_Unit_Proof( array( 'origin' => 'proposal', 'ops' => array( AB_MCP_Sync_Canon::encode( $good ) ) ) ); // @phpstan-ignore-line
			self::fail( 'No proof by new.' );
		} catch ( \Error $e ) {
			self::assertStringContainsString( 'private', $e->getMessage() );
		}
		$real = self::confirmation( array( $good ) );
		self::assertInstanceOf( AB_MCP_Sync_Unit_Proof::class, $real );
		try {
			$copy = clone $real;
			self::fail( 'No copy of a proof.' );
		} catch ( \Error $e ) {
			self::assertStringContainsString( 'private', $e->getMessage() );
		}
		foreach ( array( serialize( $real ), 'O:22:"AB_MCP_Sync_Unit_Proof":1:{s:30:"' . "\0" . 'AB_MCP_Sync_Unit_Proof' . "\0" . 'fields";a:2:{s:6:"origin";s:8:"proposal";s:3:"ops";a:0:{}}}' ) as $text ) {
			try {
				unserialize( $text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				self::fail( 'A proof is never unserialized.' );
			} catch ( \LogicException $e ) {
				self::assertStringContainsString( 'never unserialized', $e->getMessage() );
			}
		}

		// A confirmation only where the token passed its check in this request,
		// for these arguments, this state and this account.
		$args  = array(
			'action' => 'confirm',
			'tag'    => 'unchecked',
		);
		$state = array( 'ops' => array( $good ) );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( $args, $state ), 'Self-issued: no check passed.' );
		$hash = hash( 'sha256', AB_MCP_Sync_Canon::encode( $state ) );
		self::assertNotTrue( AB_MCP_Confirm_Token::check( 'wp_site_pairs', $args, $hash, AB_MCP_Confirm_Token::issue( 'wp_site_pairs', array( 'tag' => 'other' ) + $args, $hash, time() ), time() ), 'A token of other arguments does not pass.' );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( $args, $state ), 'A check that did not pass records nothing.' );
		self::assertTrue( AB_MCP_Confirm_Token::check( 'wp_site_pairs', $args, $hash, AB_MCP_Confirm_Token::issue( 'wp_site_pairs', $args, $hash, time() ), time() ) );
		self::assertInstanceOf( AB_MCP_Sync_Unit_Proof::class, AB_MCP_Sync_Unit_Proof::of_confirmation( $args, $state ) );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( array( 'tag' => 'other' ) + $args, $state ), 'Other arguments.' );
		$wider = array( 'ops' => array( $good, self::op( 'theme', 'look', array( 'files' => 'equal' ), self::line( 'theme', 'look' ), self::line( 'theme', 'look' ) ) ) );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( $args, $wider ), 'Another state: an operation added after the check.' );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( array( 'action' => 'discard' ) + $args, $state ) );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( $args + array( 'confirm_token' => 'x' ), array( 'print' => 'p' ) + $state ) );
		wp_set_current_user( 0 );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( $args, $state ), 'Another account.' );
		wp_set_current_user( self::admin() );
		$scope = new \ReflectionProperty( \AB_MCP_Auth::class, 'current_scope' );
		$was   = $scope->getValue();
		$scope->setValue( null, 'content' === $was ? 'full' : 'content' );
		try {
			self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( $args, $state ), 'Another access level.' );
		} finally {
			$scope->setValue( null, $was );
		}
		self::assertInstanceOf( AB_MCP_Sync_Unit_Proof::class, AB_MCP_Sync_Unit_Proof::of_confirmation( $args, $state ), 'The same once more.' );
		$tool = array( 'tag' => 'tool' ) + $args;
		self::assertTrue( AB_MCP_Confirm_Token::check( 'wp_site_revert', $tool, $hash, AB_MCP_Confirm_Token::issue( 'wp_site_revert', $tool, $hash, time() ), time() ) );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_confirmation( $tool, $state ), 'A token of another tool.' );

		// The proof of a step only from a step done whose journal names a unit.
		self::assertNull( self::step_proof( 'src-2b', 'plugin', 'shop', array( 'files' ), array( 'activation' ), self::checked( 'plugin', $l ), 'planned' ), 'A step not done.' );
		$none = \AB_MCP_IT_Pair_Rows::journal( 'src-2b', array( 'note' => 'no unit' ) );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_step( $none['run_id'], $none['seq'] ), 'A result without a unit.' );
		self::assertNull( AB_MCP_Sync_Unit_Proof::of_step( 999999, 1 ), 'No such run.' );
		// A proof of one source is none for another (A2), and holds its unit only.
		$w     = self::line( 'plugin', 'shop' );
		$push  = self::op( 'plugin', 'shop', array( 'files' => 'written', 'activation' => 'written' ), $w, $w, array( 'source' => null ) );
		$proof = self::step_proof( 'src-2b', 'plugin', 'shop', array( 'files', 'activation' ), array(), self::checked( 'plugin', $w ) );
		self::assertSame( 'step', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-other', $push, $proof ) ) );
		$cart = self::line( 'plugin', 'cart', array( 'hash' => $w['hash'] ) );
		self::assertSame( 'step', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', self::op( 'plugin', 'cart', array( 'files' => 'written', 'activation' => 'written' ), $cart, $cart ), $proof ) ), 'Another unit.' );
		$look = self::line( 'theme', 'shop', array( 'hash' => $w['hash'] ) );
		self::assertSame( 'step', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', self::op( 'theme', 'shop', array( 'files' => 'written' ), $look, $look ), $proof ) ), 'A unit of another kind of the same name.' );
		self::assertSame( 'proof', self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-other', $good, $real ) ) );
		self::assertSame( 0, $rows() );
		// The same proofs for their own source and unit: written.
		self::assertIsArray( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $good, $real ) );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) ); // phpcs:ignore WordPress.DB
		self::assertIsArray( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $push, $proof ) );
	}

	/* -------------------------- the way back of a unit (Behebung 1, A3) */

	public function testAUnitGoesBackOnlyToTheStateItsJournalNames(): void {
		$fields = array(
			'source_id'    => 'src-2b',
			'kind'         => 'plugin',
			'live_ref'     => 'shop',
			'staging_ref'  => 'shop',
			'canon'        => AB_MCP_Sync_Canon::VERSION,
			'base_live'    => hash( 'sha256', 'l' ),
			'base_staging' => hash( 'sha256', 's' ),
			'base_origin'  => 'push',
			'base_extra'   => array( 'parts' => array( 'activation' => array( 'live' => hash( 'sha256', 'al' ), 'staging' => hash( 'sha256', 'as' ) ) ) ),
		);
		$pair   = \AB_MCP_IT_Pair_Rows::put( $fields );
		$id     = (int) $pair['id'];
		$before = AB_MCP_Sync_Pairs::snapshot( $id );
		$now    = static fn(): string => AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $id ) );
		// The step moved the files on; its journal names the state before.
		global $wpdb;
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'base_live' => hash( 'sha256', 'l2' ), 'base_staging' => hash( 'sha256', 'l2' ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		$after = AB_MCP_Sync_Pairs::snapshot( $id );
		$step  = \AB_MCP_IT_Pair_Rows::journal( 'src-2b', null, $id, $before );

		// A snapshot of the same identity with other bases: never (red at 012ba9b).
		$forged                            = json_decode( $before, true );
		$forged['columns']['base_live']    = hash( 'sha256', 'made up' );
		$forged['columns']['base_staging'] = hash( 'sha256', 'made up' );
		$forged                            = (string) json_encode( $forged );
		$cases                             = array(
			'other bases, no step'         => array( $forged, null ),
			'other bases, with its step'   => array( $forged, $step ),
			'its snapshot, no step'        => array( $before, null ),
			'its snapshot, a step of none' => array( $before, array( 'run_id' => 999999, 'seq' => 1 ) ),
			'of another pair'              => array( $before, \AB_MCP_IT_Pair_Rows::journal( 'src-2b', null, $id + 1000, $before ) ),
			'of another source'            => array( $before, \AB_MCP_IT_Pair_Rows::journal( 'src-other', null, $id, $before ) ),
			'the step names another state' => array( $before, \AB_MCP_IT_Pair_Rows::journal( 'src-2b', null, $id, $after ) ),
		);
		foreach ( $cases as $case => list( $snapshot, $by ) ) {
			$result = AB_MCP_Sync_Pairs::restore( $id, $snapshot, $now(), $by );
			self::assertInstanceOf( WP_Error::class, $result, $case );
			self::assertSame( array( 'ab_mcp_staging_pair_unit', 'restore' ), array( $result->get_error_code(), $result->get_error_data()['clause'] ?? null ), $case );
			self::assertSame( $after, AB_MCP_Sync_Pairs::snapshot( $id ), $case . ': nothing written.' );
		}
		// The state its journal names: back.
		$back = AB_MCP_Sync_Pairs::restore( $id, $before, $now(), $step );
		self::assertIsArray( $back, is_wp_error( $back ) ? $back->get_error_message() : '' );
		self::assertSame( $before, AB_MCP_Sync_Pairs::snapshot( $id ) );
		// An object goes back to its snapshot as before, without a step.
		$post = AB_MCP_Sync_Pairs::create( array( 'kind' => 'post', 'live_ref' => '5', 'staging_ref' => '9', 'base_origin' => 'proposal' ) + array_diff_key( $fields, array( 'kind' => 1, 'live_ref' => 1, 'staging_ref' => 1, 'base_origin' => 1, 'base_extra' => 1 ) ) );
		$post_before = AB_MCP_Sync_Pairs::snapshot( (int) $post['id'] );
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'base_live' => hash( 'sha256', 'p2' ) ), array( 'id' => (int) $post['id'] ) ); // phpcs:ignore WordPress.DB
		self::assertIsArray( AB_MCP_Sync_Pairs::restore( (int) $post['id'], $post_before, AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $post['id'] ) ) ) );
		self::assertSame( $post_before, AB_MCP_Sync_Pairs::snapshot( (int) $post['id'] ) );
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
		$modes  = array( 'files' => 'written', 'activation' => 'live_after_write' );
		$pushed = static fn( array $l, array $written, array $after, ?array $checked = null, array $more = array(), ?array $as = null ) => AB_MCP_Sync_Pairs::set_unit_base( $source, self::op( 'plugin', 'ab-it-inaktiv', $as ?? $modes, $s, $l, array( 'source' => null ) + $more ), self::step_proof( $source, 'plugin', 'ab-it-inaktiv', $written, $after, $checked ?? self::checked( 'plugin', $l ) ) );

		// Not written yet, or written with another version: no base.
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.0.0' );
		$other = self::lines_here()['plugin:ab-it-inaktiv'];
		self::assertSame( 'state', self::why( $pushed( $other, array( 'files' ), array( 'activation' ) ) ), 'Written, but not as staging has it: checked, no base.' );
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$l = self::lines_here()['plugin:ab-it-inaktiv'];
		self::assertSame( 'step', self::why( $pushed( $l, array(), array( 'activation' ) ) ), 'The journal: the files not written.' );
		self::assertSame( 'step', self::why( $pushed( $l, array( 'files' ), array( 'activation' ), array( 'files' => hash( 'sha256', 'other' ) ) + self::checked( 'plugin', $l ) ) ), 'Checked with another hash.' );
		self::assertSame( 'written', self::why( $pushed( $l, array( 'files' ), array( 'activation' ), null, array(), array( 'files' => 'live_after_write', 'activation' => 'live_after_write' ) ) ), 'The files written, or no base of origin push.' );
		self::assertSame( 'whole', self::why( $pushed( array( 'complete' => false, 'hash' => null ) + $l, array( 'files' ), array( 'activation' ) ) ), 'Not checked: the line after the write not read whole.' );
		self::assertSame( 'side', self::why( AB_MCP_Sync_Pairs::set_unit_base( $source, self::op( 'plugin', 'ab-it-inaktiv', $modes, $s, null, array( 'source' => null ) ), self::step_proof( $source, 'plugin', 'ab-it-inaktiv', array( 'files' ), array( 'activation' ), self::checked( 'plugin', $l ) ) ) ), 'No line after the write: unchecked.' );
		self::assertSame( array(), self::pairs() );

		// Written and checked: the files as staging has them, the activation
		// where this site stands after it (made inactive), so the activation on
		// staging is a change on staging, which the activation step takes over.
		$pair = $pushed( $l, array( 'files' ), array( 'activation' ), null, array( 'last_run' => 7 ) );
		self::assertIsArray( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		$act = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $l );
		self::assertSame( array( 'push', 7, $s['hash'], $s['hash'] ), array( $pair['base_origin'], $pair['last_run'], $pair['base_live'], $pair['base_staging'] ) );
		self::assertSame( array( 'live' => $act, 'staging' => $act ), $pair['base_extra']['parts']['activation'] );
		self::assertSame( array( 'activation' => 'live_after_write', 'files' => 'written' ), $pair['base_extra']['modes'], 'B5: what was written and checked, and what is as this site has it after the write.' );
		self::assertSame( 'written', self::why( $pushed( $l, array( 'files' ), array( 'activation' ), null, array( 'pair_id' => (int) $pair['id'] ) ) ), 'Only for a unit without a pair.' );
		$env = self::report();
		self::assertSame( array( 'activation' => 'changed_on_staging', 'files' => 'equal' ), $env['plugin:ab-it-inaktiv']['parts'], 'F5: the activation follows as a change on staging.' );
	}

	public function testAPartAStepDidNotWriteIsKeptApartFromOneItWroteAndChecked(): void {
		// B5 (Lesart der Lead, noch von der Steuerung zu bestätigen): a part
		// the step did not write takes the state of this site after the write
		// as its base (live_after_write), never as written and checked; the
		// journal names which, and the proof of one does not cover the other.
		$s     = self::line( 'plugin', 'shop' );
		$off   = self::line( 'plugin', 'shop', array( 'active' => array() ) );
		$cases = array(
			'the activation said written, the journal left it'  => array( $s, array( 'files' => 'written', 'activation' => 'written' ), array( 'files' ), array( 'activation' ), 'step' ),
			'the activation said left, the journal wrote it'    => array( $s, array( 'files' => 'written', 'activation' => 'live_after_write' ), array( 'files', 'activation' ), array(), 'step' ),
			'named in both lists of the journal'                => array( $s, array( 'files' => 'written', 'activation' => 'live_after_write' ), array( 'files', 'activation' ), array( 'activation' ), 'step' ),
			'written, but not as staging has it'                => array( $off, array( 'files' => 'written', 'activation' => 'written' ), array( 'files', 'activation' ), array(), 'state' ),
			'the files left, not written'                       => array( $s, array( 'files' => 'live_after_write', 'activation' => 'written' ), array( 'activation' ), array( 'files' ), 'written' ),
			'said written, named in both lists'                 => array( $s, array( 'files' => 'written', 'activation' => 'written' ), array( 'files', 'activation' ), array( 'activation' ), 'step' ),
			'said written, in neither list'                     => array( $s, array( 'files' => 'written', 'activation' => 'written' ), array( 'files' ), array(), 'step' ),
			'said left, in neither list'                        => array( $s, array( 'files' => 'written', 'activation' => 'live_after_write' ), array( 'files' ), array(), 'step' ),
		);
		foreach ( $cases as $case => list( $l, $modes, $written, $after, $why ) ) {
			$proof = self::step_proof( 'src-2b', 'plugin', 'shop', $written, $after, self::checked( 'plugin', $l ) );
			self::assertSame( $why, self::why( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', self::op( 'plugin', 'shop', $modes, $s, $l ), $proof ) ), $case );
		}
		self::assertSame( array(), AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );
		$left = AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', self::op( 'plugin', 'shop', array( 'files' => 'written', 'activation' => 'live_after_write' ), $s, $off ), self::step_proof( 'src-2b', 'plugin', 'shop', array( 'files' ), array( 'activation' ), self::checked( 'plugin', $off ) ) );
		self::assertIsArray( $left, is_wp_error( $left ) ? $left->get_error_message() : '' );
		$act = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $off );
		self::assertSame( array( 'live' => $act, 'staging' => $act ), $left['base_extra']['parts']['activation'], 'The state of this site after the write, the base of both sides.' );
		self::assertSame( array( 'activation' => 'live_after_write', 'files' => 'written' ), $left['base_extra']['modes'], 'Stored apart from written.' );
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
		$line  = self::line( 'plugin', 'shop' );
		$op    = self::op( 'plugin', 'shop', array( 'files' => 'written', 'activation' => 'written' ), $line, $line );
		$rows  = static fn(): int => count( AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );
		$proof = self::step_proof( 'src-2b', 'plugin', 'shop', array( 'files', 'activation' ), array(), self::checked( 'plugin', $line ) );
		// V5 for units: the insert and the reading back in one transaction.
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Pairs::class, 'naht_sync_pairs', self::read_back_fails() );
		$result = AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $op, $proof );
		AB_MCP_Test_Seam::restore_all();
		self::refused( $result, 'ab_mcp_staging_db_error' );
		self::assertSame( 0, $rows(), 'Taken back: no pair the caller was told is not there.' );
		// A reading of the unique keys that fails (Lesefehler) writes nothing.
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Pairs::class, 'naht_sync_pairs', AB_MCP_Sync_Pairs_Calls::class, array( array( 'method' => 'row', 'nth' => 1, 'kind' => 'return', 'value' => false ) ) );
		$result = AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $op, $proof );
		AB_MCP_Test_Seam::restore_all();
		self::refused( $result, 'ab_mcp_staging_db_error' );
		self::assertSame( 0, $rows() );
		// A new alignment whose row changed in between (no row changed).
		self::assertIsArray( AB_MCP_Sync_Pairs::set_unit_base( 'src-2b', $op, $proof ) );
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
		$op    = self::op( 'plugin', 'shop', array( 'files' => 'written', 'activation' => 'written' ), $line, $line );
		$probe = __DIR__ . '/support/unit-base-probe.php';
		// The journal of the step, committed before the process of its own reads it.
		$step  = \AB_MCP_IT_Pair_Rows::journal(
			'src-2b',
			array(
				'unit' => array(
					'kind'             => 'plugin',
					'ref'              => 'shop',
					'written'          => array( 'files', 'activation' ),
					'live_after_write' => array(),
					'checked'          => self::checked( 'plugin', $line ),
				),
			)
		);
		$plan  = static function ( array $fault ) use ( $op, $probe, $step ): array {
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
						'args' => array( 'src-2b', $op, $step['run_id'], $step['seq'] ),
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
		// The head not written (Rückgabe false, Throwable): nothing kept as done.
		$seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'return', 'value' => false ) ) );
		self::refused( self::here( $begin ), 'ab_mcp_sync_pairs_unwritten' );
		$seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'throw', 'message' => 'head gone' ) ) );
		try {
			self::here( $begin );
			self::fail( 'The Throwable leaves the call.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'head gone', $e->getMessage() );
		}
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array(), array_values( array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'proposal' === $g['label'] ) ), 'No group without its head left behind.' );
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
		// B6: the first file the next call stores not written (Rückgabe false,
		// Throwable), or a file of its name claimed there that does not read
		// back against its SHA-256: refused, no proposal made of it; the call
		// after goes on.
		$go_on = array(
			'action'      => 'propose',
			'proposal_id' => $slow['proposal_id'],
		);
		$seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'return', 'value' => false ) ) );
		self::refused( self::here( $go_on ), 'ab_mcp_sync_pairs_unwritten' );
		$seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'return', 'value' => new WP_Error( 'ab_mcp_store_exists', 'There is a file of this name.' ) ) ) );
		self::refused( self::here( $go_on ), 'ab_mcp_store_exists' );
		$seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'throw', 'message' => 'entries gone' ) ) );
		try {
			self::here( $go_on );
			self::fail( 'The Throwable leaves the call.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'entries gone', $e->getMessage() );
		}
		AB_MCP_Test_Seam::restore_all();
		$done = self::here( $go_on );
		self::assertTrue( $done['done'] );
		self::assertGreaterThan( 0, $done['units']['entries'] );
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

	/**
	 * What a proposal says of its units, each by its category, confirmation,
	 * parts and reason: equal for the same environment of both sides (the
	 * objects of the site may change between two proposals of a case: the
	 * processes of its own write options of the site). Its entries numbered
	 * without a gap, as many as its counts say.
	 */
	private static function said_of( array $answer ): array {
		self::assertSame( range( 1, count( $answer['all'] ) ), array_map( static fn( $e ) => (int) $e['n'], $answer['all'] ), 'Every entry once, in order.' );
		self::assertSame( array_sum( $answer['counts'] ), count( $answer['all'] ) );
		$units = array();
		foreach ( $answer['units'] as $key => $entry ) {
			$units[ $key ] = array( $entry['category'], $entry['confirm'] ?? null, $entry['parts'] ?? null, $entry['reason'] ?? null );
		}
		ksort( $units );
		return $units;
	}

	public function testAnEndOfTheProcessWhileTheProposalOfUnitsIsStoredLeavesItToTheNextCall(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		$packages    = self::staged( 'inventory' );
		$environment = self::staged( 'env' );
		// A proposal begun: the inventory read, the environment of this site not yet.
		$begin = static function () use ( $packages, $environment ): string {
			$t                      = 0.0;
			AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
				$t += 1000.0;
				return $t;
			};
			$begun                  = self::here(
				array(
					'action'      => 'propose',
					'packages'    => $packages,
					'environment' => $environment,
				)
			);
			AB_MCP_Sync_Env::$clock = null;
			self::assertFalse( $begun['done'], 'The inventory read, the environment of this site not yet.' );
			return (string) $begun['proposal_id'];
		};
		$plan  = static function ( array $fault, string $id ): array {
			return array(
				'require' => array( AB_MCP_DIR . 'includes/sync/class-sync-proposal.php' ),
				'seams'   => array(
					array(
						'owner'    => 'AB_MCP_Sync_Proposal',
						'property' => 'naht_sync_proposal',
						'real'     => 'AB_MCP_Sync_Proposal_Calls',
						// A file this call writes through the proposal: its
						// entries (the first), then its index.
						'faults'   => array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'abort', 'point' => 'store-entries' ) + $fault ),
					),
				),
				'calls'   => array(
					array( 'call' => 'wp_set_current_user', 'args' => array( self::admin() ) ),
					array(
						'call' => 'AB_MCP_Sync_Proposal::propose',
						'args' => array( array( 'proposal_id' => $id ) ),
					),
				),
			);
		};
		$finish = static function ( string $id ): array {
			$answer = self::here( array( 'action' => 'propose', 'proposal_id' => $id ) );
			for ( $i = 0; is_array( $answer ) && false === $answer['done'] && $i < 50; $i++ ) {
				$answer = self::here( array( 'action' => 'propose', 'proposal_id' => $id ) );
			}
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			self::assertTrue( $answer['done'], 'Worked out by the next call.' );
			return self::proposal_units( $answer );
		};
		$id = $begin();
		foreach ( array( 'no_shutdown', 'shutdown', 'timeout' ) as $mode ) {
			AB_MCP_IT_Process::abort( 'a', $plan( array( 'mode' => $mode ), $id ) );
		}
		$want = self::said_of( $finish( $id ) );
		self::assertNotSame( array(), $want );
		// B6: an end after a file of the proposal is stored, or between its
		// entries and its index: the next call works it out again and meets
		// the file there (named by its SHA-256, read against it), goes on with
		// it and comes to the same proposal, never another one.
		// B6: an end of the first call right after it stored the slice of the
		// identity index of this site, before the part that names it: the
		// next call reads that part again, meets the slice there by its name
		// and SHA-256, and goes on to the same proposal.
		AB_MCP_IT_Process::abort(
			'a',
			array(
				'require' => array( AB_MCP_DIR . 'includes/sync/class-sync-proposal.php' ),
				'seams'   => array(
					array(
						'owner'    => 'AB_MCP_Sync_Proposal',
						'property' => 'naht_sync_proposal',
						'real'     => 'AB_MCP_Sync_Proposal_Calls',
						// The head first, then the slice.
						'faults'   => array( array( 'method' => 'put', 'nth' => 2, 'kind' => 'abort', 'point' => 'store-index-slice', 'mode' => 'no_shutdown', 'when' => 'after' ) ),
					),
				),
				'calls'   => array(
					array( 'call' => 'wp_set_current_user', 'args' => array( self::admin() ) ),
					array(
						'call' => 'AB_MCP_Sync_Proposal::propose',
						'args' => array(
							array(
								'packages'    => $packages,
								'environment' => $environment,
							),
						),
					),
				),
			)
		);
		$groups = array_values( array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'proposal' === $g['label'] ) );
		self::assertCount( 1, $groups );
		$names = array_keys( AB_MCP_Protected_Store::files( 'reports', $groups[0]['gid'] ) );
		self::assertSame( array( 'h', 'i' ), array_values( array_unique( array_map( static fn( $n ) => $n[0], $names ) ) ), 'The head and the slice, no part yet.' );
		self::assertSame( $want, self::said_of( $finish( $groups[0]['gid'] ) ), 'after the slice of the index is stored' );
		foreach ( array(
			'after the entries are stored'    => array( 'when' => 'after' ),
			'before the index is stored'      => array( 'nth' => 2 ),
			'after the index is stored'       => array( 'nth' => 2, 'when' => 'after' ),
		) as $case => $at ) {
			$id = $begin();
			AB_MCP_IT_Process::abort( 'a', $plan( $at + array( 'mode' => 'no_shutdown' ), $id ) );
			self::assertSame( $want, self::said_of( $finish( $id ) ), $case );
		}
		self::assertSame( array(), self::pairs() );
	}

	/** The answer of a proposal with its entries of units by kind:ref (all pages). */
	private static function proposal_units( array $answer ): array {
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
		// A2: the tool past the gate, its plan made (state()) but no token
		// checked: what confirms the unit is not made, nothing written.
		self::assertIsString( AB_MCP_Sync_Pairs_Tool::state( $args( 'live_base' ) ) );
		$past = AB_MCP_Sync_Pairs_Tool::call( $args( 'live_base' ) );
		self::assertStringContainsString( 'only with what confirms it', self::refused( $past, 'ab_mcp_sync_pairs_confirm_failed' ) );
		self::assertSame( array(), self::pairs(), 'A2: no pair without the check of the token.' );
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
		// A unit on this site only, whose pair a run left unclear.
		$this->own_plugin( 'a', 'ab-it-nur-hier.php' );
		$alone = \AB_MCP_IT_Pair_Rows::put( array( 'state' => 'unclear', 'live_ref' => 'ab-it-nur-hier.php', 'staging_ref' => 'ab-it-nur-hier.php' ) + $fields );
		$answer  = self::proposal();
		$realign = array();
		$count   = array();
		foreach ( $answer['all'] as $entry ) {
			if ( 'realign' === $entry['category'] ) {
				$realign[ $entry['pair']['id'] ] = $entry;
				$count[ $entry['pair']['id'] ]   = ( $count[ $entry['pair']['id'] ] ?? 0 ) + 1;
			}
		}
		ksort( $realign );
		ksort( $count );
		self::assertSame( array( (int) $same['id'] => 1, (int) $diff['id'] => 1, (int) $gone['id'] => 1, (int) $alone['id'] => 1 ), $count, 'One entry each, never also as an object of the proposal (V4).' );
		self::assertSame( array(), array_values( array_filter( $answer['all'], static fn( $e ) => in_array( $e['kind'], AB_MCP_Sync_Decision::UNIT_KINDS, true ) && empty( $e['unit'] ) ) ), 'No entry of a unit kind as an object.' );
		self::assertSame( array( null, 'A new alignment needs both sides of the unit read whole, each part of it. To end the pair, call wp_site_pairs with action=discard and its pair id.' ), array( $realign[ (int) $alone['id'] ]['confirm'], $realign[ (int) $alone['id'] ]['reason'] ), 'On one side only: not aligned anew.' );
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
		// select alone: the part that is the same; the one that differs open.
		$alone_part = self::gated( $c( array( 'select' => array( $realign[ (int) $diff['id'] ]['n'] ) ) ) );
		self::assertSame( array( array( 'activation' => 'equal' ), array( 'files' ) ), array( $alone_part['preview']['pairs'][0]['parts'], $alone_part['preview']['pairs'][0]['open'] ) );
		list( $preview, $done ) = self::confirmed(
			$c(
				array(
					'select' => array( $realign[ (int) $same['id'] ]['n'], $realign[ (int) $diff['id'] ]['n'] ),
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
			self::assertSame(
				array(
					'kept'  => 1,
					'modes' => array( 'activation' => 'equal', 'files' => 'plugin:ab-it-zwei' === $key ? 'equal' : 'live_base' ),
					'parts' => array( 'activation' => array( 'live' => $act, 'staging' => $act ) ),
				),
				$pair['base_extra'],
				$key . ': every part anew, the rest kept.'
			);
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
