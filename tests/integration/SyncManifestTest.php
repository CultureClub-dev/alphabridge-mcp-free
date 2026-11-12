<?php
/**
 * The manifests of code and files over two WordPress sites (Z8b branch 2;
 * plan 3.1 Z1, Z2, Z35, Z38; abnahme F4, F7, F17, F22, F41, the report
 * parts of F46, F47 and the separate bases of F51), with the test plugins
 * and themes of branch 1 (AB_MCP_IT_Code) on both sites:
 *
 * - the normal case: a report between two usual sites shows every plugin
 *   and theme that is the same as equal, without a lock; three files
 *   changed in one of them (the same version) as differing with those three
 *   paths (F4); only which main files run as «differs: activation» (F7); a
 *   folder .git live and a link on staging as code:live_extra and
 *   code:link (F22); the sites live in other folders (F41);
 * - with pairs: a plugin that writes into its folder on both sites and a
 *   new version on staging as code:live_changed without a choice, with the
 *   way «update by hand» and the path of its log (F22, F46); a path the new
 *   version no longer has as a path only on this site, with the sentence of
 *   the backup (F47); the activation changed here and the files on staging,
 *   each part against its own base (F51);
 * - Z38: rebase() moves on only the part of the kind responsible for it,
 *   with the faults of its calls to the database (seam naht_sync_pairs);
 * - Z35: the core files of this site checked against the stored answer of
 *   api.wordpress.org, one changed (F17);
 * - exit/Fatal in a process of its own while a unit is hashed: nothing is
 *   left, the next reading is whole.
 *
 * Codex round 1 (Behebung 1): a plugin of one file without a version is a
 * unit up to the report (B2); a folder of plugins the staging site cannot
 * list leaves the plugins here not compared (B4); every path of a unit is
 * in the report, the paths past a hundred in entries the cursor reaches
 * (B5); and the matrix of plan 8.3 (B6) below.
 *
 * THE MATRIX OF PLAN 8.3, branch 2: each effect to the outside with each kind
 * of fault that applies, where it is tested, or why it does not apply.
 *
 * Files (reading the units; seam naht_sync_file_manifest):
 * - return false, Lesefehler, Throwable, halb gelesen, gleichzeitiger
 *   Schreiber, Zeitlimit: SyncFileManifestTest (each call of the seam);
 * - exit/Fatal: testAnEndOfTheProcessWhileAUnitIsHashedLeavesNoHalfReading;
 * - halb geschrieben: does not apply, the reading writes no file.
 * Rows and options of the series (seam naht_sync_env: the option rows the
 * lines take, the file lists of the attachments):
 * - return false/null (Lesefehler), Throwable, gleichzeitiger Schreiber
 *   (the theme switched while it is read):
 *   testTheRowsOfTheSeriesThatFailOrChangeWhileReadAreNeverComparedHalf;
 * - exit/Fatal and Zeitlimit (the time limit of PHP) in a process of its
 *   own: testAnEndOfTheProcessOrItsTimeLimitWhileTheSeriesReadsLeavesNothing;
 *   the budget of a call (the clock of a part): SyncEnvTest of both suites;
 * - halb geschrieben: does not apply to the rows, the series reads them
 *   only; the parts it gives are written by the export and the report into
 *   the protected store, one file at a time through a rename
 *   (ProtectedStoreTest, testAFileIsWrittenOnceThroughARenameAndReadOnlyWithItsHash).
 * Rows of the pairs and their bases by part (seam naht_sync_pairs):
 * - return false, Lesefehler, no row changed, gleichzeitiger Schreiber,
 *   Throwable: testAFaultOfTheDatabaseLeavesTheBasesAsTheyWere;
 * - exit/Fatal and Zeitlimit before and after the write, in a process of
 *   its own: testAnEndOfTheProcessOrItsTimeLimitAtTheWriteOfTheBasesLeavesThemWhole;
 * - halb geschrieben: does not apply, the bases of a part are written by one
 *   UPDATE of one row (all columns or none; the end after it leaves them
 *   whole and new, the test above).
 * HTTP (the checksums of api.wordpress.org; seam naht_sync_core_compare):
 * - return false, Throwable, halb gelesen (an answer cut), another answer,
 *   Zeitlimit of the budget: SyncCoreCompareTest;
 * - exit/Fatal and the time limit of PHP at the request, in a process of
 *   its own: testAnEndOfTheProcessOrItsTimeLimitWhileTheSeriesReadsLeavesNothing;
 * - gleichzeitiger Schreiber, halb geschrieben: do not apply, the request
 *   writes nothing, and a core file changed while it is checked differs.
 * Hooks of others: none (plan 8.3), the series calls no filter.
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
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Core_Compare;
use AB_MCP_Sync_Core_Compare_Calls;
use AB_MCP_Sync_Decision;
use AB_MCP_Sync_Env;
use AB_MCP_Sync_Env_Calls;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Pairs_Calls;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-env.php';

final class SyncManifestTest extends TestCase {

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	/** @var array The kinds registered before the case. */
	private $kinds = array();

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
		$this->kinds = AB_MCP_Sync_Kinds::all();
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Report::$page = null;
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
		AB_MCP_Sync_Kinds::reset();
		foreach ( $this->kinds as $kind ) {
			AB_MCP_Sync_Kinds::register( $kind );
		}
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Env::$clock = null;
		AB_MCP_Sync_Env::forget();
		AB_MCP_IT_Code::clear();
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Code::clear();
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
	}

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

	/** B packs one purpose (a series) and A takes it over: the package ids. */
	private static function staged( string $purpose ): array {
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

	/** The entries of the environment of a report worked out to its end, by subtype:ref. */
	private static function report(): array {
		$answer = AB_MCP_Sync_Report::call(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => self::staged( 'env' ),
			)
		);
		$calls = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
			self::assertLessThan( 100, ++$calls );
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
			if ( 'environment' === $entry['kind'] ) {
				// The further paths of a unit by their list and first number.
				$more = 'more_paths' === $entry['result'] ? '#' . $entry['paths']['list'] . '@' . $entry['paths']['from'] : '';
				$out[ $entry['subtype'] . ':' . $entry['ref'] . $more ] = $entry;
			}
		}
		return $out;
	}

	private static function said( array $entry ): array {
		return array( $entry['result'], $entry['action'], array_column( $entry['locks'] ?? array(), 'key' ) );
	}

	/** The line of a unit of this site as the reading writes it. */
	private static function line_here( string $kind, string $name ): array {
		$read = AB_MCP_Sync_Env::read( null, 60, 4000, 'live' );
		self::assertIsArray( $read );
		self::assertNull( $read['state'] );
		foreach ( $read['lines'] as $line ) {
			if ( $kind === $line['env'] && $name === $line['ref'] ) {
				return $line;
			}
		}
		throw new RuntimeException( "No line $kind $name on this site." );
	}

	/** A pair of a plugin of the test whose two sides are the same as here now. */
	private static function pair_of( string $slug ): array {
		$line = self::line_here( 'plugin', $slug );
		$act  = AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', $line );
		$pair = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => AB_MCP_Sync_Anchor::active()['source_id'],
				'kind'         => 'plugin',
				'live_ref'     => $slug,
				'staging_ref'  => $slug,
				'canon'        => AB_MCP_Sync_Canon::VERSION,
				'base_live'    => $line['hash'],
				'base_staging' => $line['hash'],
				'base_origin'  => 'proposal',
				'base_extra'   => array(
					'parts' => array(
						'activation' => array(
							'live'    => $act,
							'staging' => $act,
						),
					),
				),
			)
		);
		self::assertIsArray( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		return $pair;
	}

	/* ------------------------------------------------- the normal case */

	public function testAReportBetweenTwoUsualSitesShowsCodeAsEqualOrDifferent(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		// F4: three files of a plugin of its own changed on staging, the same version.
		$basis = AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-basis' );
		file_put_contents( $basis . '/ab-it-basis.php', "\n// changed", FILE_APPEND );
		file_put_contents( $basis . '/neu-a.php', '<?php // a' );
		file_put_contents( $basis . '/neu-b.php', '<?php // b' );
		// F7: only which main file runs differs.
		AB_MCP_IT_Process::calls( 'b', array( 'calls' => array( array( 'call' => 'deactivate_plugins', 'args' => array( 'ab-it-allein/ab-it-allein.php', true ) ) ) ) );
		// F22: a folder .git here, a link on staging.
		mkdir( AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' ) . '/.git' );
		file_put_contents( AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' ) . '/.git/HEAD', 'ref' );
		symlink( '/etc/hosts', AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-routinen' ) . '/hosts' );
		// B2: a plugin of one file without a version, the same on both sites.
		foreach ( array( 'a', 'b' ) as $site ) {
			$this->made[] = AB_MCP_IT_Sites::docroot( $site ) . '/wp-content/plugins/ab-it-ohne-version.php';
			file_put_contents( end( $this->made ), "<?php\n/*\n * Plugin Name: Ohne Version\n */\n" );
		}
		// B5: a new version on staging with 150 files more (a larger update):
		// every path in the report, past a hundred through the cursor.
		$daten = AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-daten' );
		mkdir( $daten . '/viele' );
		for ( $i = 1; $i <= 150; $i++ ) {
			file_put_contents( sprintf( '%s/viele/%03d.php', $daten, $i ), '<?php // ' . $i );
		}
		AB_MCP_Sync_Report::$page = 5;

		$env = self::report();
		$katalog = AB_MCP_IT_Code::katalog();
		foreach ( array_keys( $katalog['plugins'] ) as $slug ) {
			if ( in_array( $slug, array( 'ab-it-basis', 'ab-it-allein', 'ab-it-inaktiv', 'ab-it-routinen', 'ab-it-schreiber', 'ab-it-daten' ), true ) ) {
				continue;
			}
			$key = 'plugin:' . ( empty( $katalog['plugins'][ $slug ]['single'] ) ? $slug : $slug . '.php' );
			self::assertArrayHasKey( $key, $env );
			self::assertSame( array( 'equal', 'none', array() ), self::said( $env[ $key ] ), $key . ': the same files, equal, no lock (normal case, F41: in other folders).' );
		}
		foreach ( array( 'ab-it-eltern', 'ab-it-kind' ) as $theme ) {
			self::assertSame( array( 'equal', 'none', array() ), self::said( $env[ 'theme:' . $theme ] ), $theme );
		}
		self::assertSame( array( 'equal', 'none', array() ), self::said( $env['theme_choice:theme_choice'] ) );

		$e = $env['plugin:ab-it-basis'];
		self::assertSame( array( 'differs', 'locked', array( 'env:plugin' ) ), self::said( $e ), 'F4: the same version, other files.' );
		self::assertSame( array( 'files' ), $e['differs'] );
		self::assertSame( array( 'ab-it-basis.php' ), $e['paths']['differs'] );
		self::assertSame( array( 'neu-a.php', 'neu-b.php' ), $e['paths']['only_staging'] );
		self::assertSame( array(), $e['paths']['only_live'] );
		self::assertSame( $e['staging']['version'], $e['live']['version'] );

		$e = $env['plugin:ab-it-allein'];
		self::assertSame( array( 'differs', array( 'activation' ) ), array( $e['result'], $e['differs'] ), 'F7: only the activation differs.' );
		self::assertArrayNotHasKey( 'paths', $e );

		self::assertSame( array( 'not_compared', 'locked', array( 'code:live_extra' ) ), self::said( $env['plugin:ab-it-inaktiv'] ), 'F22: .git here.' );
		self::assertSame( array( 'not_compared', 'locked', array( 'code:link' ) ), self::said( $env['plugin:ab-it-routinen'] ), 'F22: a link on staging.' );
		self::assertStringNotContainsString( '/etc/hosts', (string) json_encode( $env ) );

		self::assertArrayHasKey( 'plugin:ab-it-ohne-version.php', $env, 'B2: a plugin of one file without a version is a unit.' );
		$e = $env['plugin:ab-it-ohne-version.php'];
		self::assertSame( array( 'equal', 'none', array() ), self::said( $e ) );
		self::assertSame( array( null, null, array( 'ab-it-ohne-version.php' ), 'file' ), array( $e['staging']['version'], $e['live']['version'], $e['live']['mains'], $e['live']['form'] ) );

		$e = $env['plugin:ab-it-daten'];
		self::assertSame( array( 'differs', array( 'files' ) ), array( $e['result'], $e['differs'] ), 'B5: the larger update.' );
		self::assertSame( array( 150, 100, 1 ), array( $e['paths']['only_staging_count'], count( $e['paths']['only_staging'] ), $e['paths']['more'] ) );
		$more = $env['plugin:ab-it-daten#only_staging@101'];
		self::assertSame( array( 'more_paths', 'none', 50 ), array( $more['result'], $more['action'], count( $more['paths']['paths'] ) ) );
		$every = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$every[] = sprintf( 'viele/%03d.php', $i );
		}
		self::assertSame( $every, array_merge( $e['paths']['only_staging'], $more['paths']['paths'] ), 'Every path, in its order, through the cursor (5 entries a page).' );
	}

	/* ------------------------------------------------ B4: not listed */

	public function testAFolderOfPluginsTheStagingSiteCannotListLeavesThePluginsHereNotCompared(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		// A file of the uploads here, outside the file lists of attachments.
		$year = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads/2098';
		self::assertFalse( file_exists( $year ) );
		mkdir( $year . '/01', 0755, true );
		$this->made[]  = $year . '/01/frei.txt';
		$this->folders = array( $year . '/01', $year );
		file_put_contents( end( $this->made ), 'frei' );
		$uploads = AB_MCP_IT_Sites::docroot( 'b' ) . '/wp-content/uploads';
		if ( ! is_dir( $uploads ) ) {
			mkdir( $uploads );
			$this->folders[] = $uploads;
		}
		$plugins = AB_MCP_IT_Sites::docroot( 'b' ) . '/wp-content/plugins';
		$before  = array(
			$plugins => fileperms( $plugins ) & 0777,
			$uploads => fileperms( $uploads ) & 0777,
		);
		// Listed by nobody, their files still loaded by their paths.
		foreach ( $before as $folder => $mode ) {
			chmod( $folder, 0311 );
		}
		try {
			self::assertFalse( @scandir( $plugins ), 'The folder of the plugins of the staging site cannot be listed.' );
			$env = self::report();
		} finally {
			foreach ( $before as $folder => $mode ) {
				chmod( $folder, $mode );
			}
		}
		self::assertSame( 'not_compared', $env['plugin:*']['result'], 'The folder says so.' );
		self::assertStringContainsString( 'on the staging site', $env['plugin:*']['why'] );
		$e = $env['plugin:ab-it-basis'];
		self::assertSame( array( 'not_compared', 'none', array() ), self::said( $e ), 'Not only on this site (V1, Codex round 1 B4).' );
		self::assertStringContainsString( 'Whether it is on the staging site is not known', $e['why'] );
		self::assertSame( array( 'equal', 'none', array() ), self::said( $env['theme:ab-it-eltern'] ), 'The themes are listed whole.' );
		$e = $env['upload:2098/01/frei.txt'];
		self::assertSame( array( 'not_compared', 'none', array() ), self::said( $e ), 'The root of the uploads of staging not listed: a file here not only here.' );
		self::assertStringContainsString( 'Whether it is on the staging site is not known', $e['why'] );
		self::assertSame( 'not_compared', $env['upload:*']['result'] );
	}

	/* ---------------------------------------------------- with pairs */

	public function testAUnitWithAPairIsJudgedByEachPartAgainstItsOwnBase(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		AB_MCP_IT_Process::calls( 'a', array( 'calls' => array() ) );
		$schreiber = self::pair_of( 'ab-it-schreiber' );
		$daten     = self::pair_of( 'ab-it-daten' );
		$allein    = self::pair_of( 'ab-it-allein' );
		self::assertSame( 'active', $schreiber['state'] );

		// F46: a new version on staging, and the plugin writes its log here again.
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-schreiber', '1.1.0' );
		AB_MCP_IT_Process::calls( 'a', array( 'calls' => array() ) );
		// F47: the new version on staging has no data/export.csv.
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-daten', '1.1.0' );
		// F51: deactivated here, the files unchanged on both sides.
		AB_MCP_IT_Process::calls( 'a', array( 'calls' => array( array( 'call' => 'deactivate_plugins', 'args' => array( 'ab-it-allein/ab-it-allein.php', true ) ) ) ) );

		$env = self::report();
		$e   = $env['plugin:ab-it-schreiber'];
		self::assertSame( array( 'conflict', 'locked', array( 'code:live_changed', 'env:plugin' ) ), self::said( $e ), 'F46: changed on both sides, no choice «staging wins».' );
		self::assertSame( 'conflict', $e['parts']['files'] );
		self::assertArrayNotHasKey( 'choices', $e );
		self::assertStringContainsString( 'update it by hand in wp-admin', $e['locks'][0]['reason'] );
		self::assertContains( 'log/zugriffe.log', $e['paths']['differs'], 'The path of its log.' );
		self::assertSame( (int) $schreiber['id'], $e['pair']['id'] );

		$e = $env['plugin:ab-it-daten'];
		self::assertSame( array( 'changed_on_staging', 'locked', array( 'env:plugin' ) ), self::said( $e ) );
		self::assertSame( array( 'data/export.csv' ), $e['paths']['only_live'], 'F47: the path the new version does not have.' );
		self::assertStringContainsString( 'into the backup', $e['words'] );

		$e = $env['plugin:ab-it-allein'];
		self::assertSame( array( 'activation' => 'changed_live', 'files' => 'equal' ), $e['parts'], 'F51: the activation changed here, its own base.' );
		self::assertSame( array( 'changed_live', 'keep', array() ), self::said( $e ) );
		self::assertSame( (int) $allein['id'], $e['pair']['id'] );
		self::assertSame( (int) $daten['id'], $env['plugin:ab-it-daten']['pair']['id'] );
	}

	/* --------------------------------------------------------- Z38 */

	/** A kind of the code of the site, as the branches 5 and 6 bring them. */
	private static function kind( string $name, array $keys ): \AB_MCP_Sync_Kind {
		return new class( $name, $keys ) implements \AB_MCP_Sync_Kind {
			private $name;
			private $keys;
			public function __construct( $name, $keys ) {
				$this->name = $name;
				$this->keys = $keys;
			}
			public function kind() { return $this->name; }
			public function phase() { return 2; }
			public function responsibility() { return $this->keys; }
			public function inventory( $source, $cursor ) { return array(); }
			public function canonical( $object ) { return array(); }
			public function export( array $refs ) { return array(); }
			public function rights( $op, $ref ) { return true; }
			public function core_effects( $op ) { return array(); }
			public function fields() { return array(); }
			public function parts( array $payload ) { return array(); }
			public function backup( array $part ) { return array(); }
			public function write( array $part ) { return true; }
			public function read_back( array $part ) { return ''; }
			public function restore_part( array $part ) { return true; }
			public function post_write( array $parts ) { return true; }
			public function verify( array $pair ) { return true; }
		};
	}

	private static function h( string $text ): string {
		return hash( 'sha256', $text );
	}

	private static function unit_pair(): array {
		$pair = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => 'src-z38',
				'kind'         => 'plugin',
				'live_ref'     => 'shop',
				'staging_ref'  => 'shop',
				'canon'        => AB_MCP_Sync_Canon::VERSION,
				'base_live'    => self::h( 'files-live' ),
				'base_staging' => self::h( 'files-staging' ),
				'base_origin'  => 'proposal',
				'base_extra'   => array(
					'parts' => array(
						'activation' => array(
							'live'    => self::h( 'act-live' ),
							'staging' => self::h( 'act-staging' ),
						),
					),
				),
			)
		);
		self::assertIsArray( $pair );
		return $pair;
	}

	private static function values( string $live, string $staging ): array {
		return array(
			'canon'        => AB_MCP_Sync_Canon::VERSION,
			'base_live'    => self::h( $live ),
			'base_staging' => self::h( $staging ),
			'base_origin'  => 'push',
			'last_run'     => 7,
		);
	}

	private static function snap( array $pair ): string {
		return AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ) );
	}

	public function testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt(): void {
		AB_MCP_Sync_Kinds::reset();
		$code       = self::kind( 'code', array( 'part:plugin:files', 'part:theme:files' ) );
		$activation = self::kind( 'activation', array( 'part:plugin:activation' ) );
		self::assertTrue( AB_MCP_Sync_Kinds::register( $code ) );
		self::assertTrue( AB_MCP_Sync_Kinds::register( $activation ) );
		$pair  = self::unit_pair();
		$first = self::snap( $pair );

		$done = AB_MCP_Sync_Pairs::rebase( (int) $pair['id'], self::values( 'f2-live', 'f2-staging' ), self::snap( $pair ), 'files', $code );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertSame( array( self::h( 'f2-live' ), self::h( 'f2-staging' ), 'push', 7 ), array( $done['base_live'], $done['base_staging'], $done['base_origin'], $done['last_run'] ) );
		self::assertSame( $pair['base_extra'], $done['base_extra'], 'The base of the activation stays byte for byte.' );

		$again = AB_MCP_Sync_Pairs::rebase( (int) $pair['id'], self::values( 'a2-live', 'a2-staging' ), self::snap( $done ), 'activation', $activation );
		self::assertIsArray( $again );
		self::assertSame( array( self::h( 'f2-live' ), self::h( 'f2-staging' ) ), array( $again['base_live'], $again['base_staging'] ), 'The base of the files stays.' );
		self::assertSame( array( 'live' => self::h( 'a2-live' ), 'staging' => self::h( 'a2-staging' ) ), $again['base_extra']['parts']['activation'] );
		self::assertSame( array( 'live' => self::h( 'a2-live' ), 'staging' => self::h( 'a2-staging' ) ), AB_MCP_Sync_Pairs::part_bases( $again, 'activation' ) );
		self::assertSame( array( 'live' => self::h( 'f2-live' ), 'staging' => self::h( 'f2-staging' ) ), AB_MCP_Sync_Pairs::part_bases( $again, 'files' ) );

		$refusals = array(
			'the kind of another part'            => array( 'files', $activation, 'ab_mcp_staging_pair_part_owner' ),
			'an engine not registered so'          => array( 'files', self::kind( 'code', array( 'part:plugin:files', 'part:theme:files' ) ), 'ab_mcp_staging_pair_part_owner' ),
			'no kind'                             => array( 'files', null, 'ab_mcp_staging_pair_part_owner' ),
			'a part the pair does not have'        => array( 'contents', $code, 'ab_mcp_staging_pair_part' ),
			'no part on the pair of a unit'        => array( null, $code, 'ab_mcp_staging_pair_part' ),
		);
		foreach ( $refusals as $case => list( $part, $kind, $code_of ) ) {
			$before = AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] );
			$result = AB_MCP_Sync_Pairs::rebase( (int) $pair['id'], self::values( 'x', 'y' ), self::snap( $again ), $part, $kind );
			self::assertInstanceOf( WP_Error::class, $result, $case );
			self::assertSame( $code_of, $result->get_error_code(), $case );
			self::assertSame( $before, AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ), $case . ': nothing written.' );
		}
		$stale = AB_MCP_Sync_Pairs::rebase( (int) $pair['id'], self::values( 'x', 'y' ), $first, 'files', $code );
		self::assertSame( 'ab_mcp_staging_pair_changed', $stale->get_error_code(), 'Only on the state the step read.' );

		// An object of Z8 takes no part.
		$post = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => 'src-z38',
				'kind'         => 'post',
				'live_ref'     => '5',
				'staging_ref'  => '9',
				'canon'        => AB_MCP_Sync_Canon::VERSION,
				'base_live'    => self::h( 'p' ),
				'base_staging' => self::h( 'q' ),
				'base_origin'  => 'proposal',
			)
		);
		$result = AB_MCP_Sync_Pairs::rebase( (int) $post['id'], self::values( 'x', 'y' ), self::snap( $post ), 'files', $code );
		self::assertSame( 'ab_mcp_staging_pair_part', $result->get_error_code() );
		self::assertIsArray( AB_MCP_Sync_Pairs::rebase( (int) $post['id'], self::values( 'x', 'y' ), self::snap( $post ) ), 'An object as before.' );
	}

	public function testAFaultOfTheDatabaseLeavesTheBasesAsTheyWere(): void {
		AB_MCP_Sync_Kinds::reset();
		$code = self::kind( 'code', array( 'part:plugin:files', 'part:theme:files' ) );
		self::assertTrue( AB_MCP_Sync_Kinds::register( $code ) );
		$pair   = self::unit_pair();
		$before = AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] );
		$seam   = static function ( array $faults ): void {
			AB_MCP_Test_Seam::restore_all();
			AB_MCP_Test_Seam::install( AB_MCP_Sync_Pairs::class, 'naht_sync_pairs', AB_MCP_Sync_Pairs_Calls::class, $faults );
		};
		$cases = array(
			'the write fails (Rückgabe false)'  => array( array( 'method' => 'update_row', 'kind' => 'return', 'value' => false ), 'ab_mcp_staging_db_error' ),
			'the read fails (Lesefehler)'       => array( array( 'method' => 'row', 'kind' => 'return', 'value' => false ), 'ab_mcp_staging_db_error' ),
			'no row changed (Rückgabe 0)'       => array( array( 'method' => 'update_row', 'kind' => 'return', 'value' => 0 ), 'ab_mcp_staging_pair_changed' ),
		);
		$expected = self::snap( $pair );
		foreach ( $cases as $case => list( $fault, $code_of ) ) {
			$seam( array( $fault ) );
			$result = AB_MCP_Sync_Pairs::rebase( (int) $pair['id'], self::values( 'x', 'y' ), $expected, 'files', $code );
			self::assertInstanceOf( WP_Error::class, $result, $case );
			self::assertSame( $code_of, $result->get_error_code(), $case );
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( $before, AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ), $case . ': the bases as they were.' );
		}
		// A writer between the read and the write (gleichzeitiger Schreiber):
		// the conditional write sees it and writes nothing.
		$id = (int) $pair['id'];
		$seam(
			array(
				array(
					'method' => 'update_row',
					'kind'   => 'before',
					'before' => static function () use ( $id ) {
						global $wpdb;
						$wpdb->query( $wpdb->prepare( 'UPDATE ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' SET last_run = 99 WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
					},
				),
			)
		);
		$result = AB_MCP_Sync_Pairs::rebase( $id, self::values( 'x', 'y' ), $expected, 'files', $code );
		self::assertSame( 'ab_mcp_staging_pair_changed', $result->get_error_code() );
		AB_MCP_Test_Seam::restore_all();
		$now = AB_MCP_Sync_Pairs::get( $id );
		self::assertSame( array( 99, $pair['base_live'], $pair['base_staging'] ), array( $now['last_run'], $now['base_live'], $now['base_staging'] ), 'The other write stands, the bases are not written.' );
		// A Throwable of the write: nothing written.
		$now_hash = self::snap( $now );
		$seam( array( array( 'method' => 'update_row', 'kind' => 'throw', 'message' => 'gone' ) ) );
		try {
			AB_MCP_Sync_Pairs::rebase( $id, self::values( 'x', 'y' ), $now_hash, 'files', $code );
			self::fail( 'The Throwable leaves the call.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'gone', $e->getMessage() );
		}
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( $pair['base_live'], AB_MCP_Sync_Pairs::get( $id )['base_live'] );
	}

	/* ---------------------------------- B6: the rows of the pairs, ends */

	public function testAnEndOfTheProcessOrItsTimeLimitAtTheWriteOfTheBasesLeavesThemWhole(): void {
		$pair     = self::unit_pair();
		$id       = (int) $pair['id'];
		$probe    = dirname( __DIR__ ) . '/integration/support/rebase-probe.php';
		$plan     = static function ( array $fault, string $expected ) use ( $id, $probe ): array {
			return array(
				'require' => array( $probe ),
				'seams'   => array(
					array(
						'owner'    => 'AB_MCP_Sync_Pairs',
						'property' => 'naht_sync_pairs',
						'real'     => 'AB_MCP_Sync_Pairs_Calls',
						'faults'   => array( array( 'method' => 'update_row', 'kind' => 'abort', 'point' => 'write-bases' ) + $fault ),
					),
				),
				'calls'   => array(
					array(
						'call' => 'ab_it_rebase_probe',
						'args' => array( $id, self::values( 'n-live', 'n-staging' ), $expected ),
					),
				),
			);
		};
		$first = self::snap( $pair );
		$cases = array(
			'exit before the write (no shutdown)' => array( 'mode' => 'no_shutdown' ),
			'exit before the write (shutdown)'    => array( 'mode' => 'shutdown' ),
			'the time limit of PHP at the write'  => array( 'mode' => 'timeout' ),
		);
		foreach ( $cases as $case => $fault ) {
			$before = AB_MCP_Sync_Pairs::snapshot( $id );
			AB_MCP_IT_Process::abort( 'a', $plan( $fault, $first ) );
			self::assertSame( $before, AB_MCP_Sync_Pairs::snapshot( $id ), $case . ': the bases as they were.' );
		}
		// The end right after the one write: the bases whole and new, both
		// columns and the part beside them (one UPDATE of one row).
		AB_MCP_IT_Process::abort( 'a', $plan( array( 'mode' => 'no_shutdown', 'when' => 'after' ), $first ) );
		$now = AB_MCP_Sync_Pairs::get( $id );
		self::assertSame( array( self::h( 'n-live' ), self::h( 'n-staging' ), 'push', 7, $pair['base_extra'] ), array( $now['base_live'], $now['base_staging'], $now['base_origin'], $now['last_run'], $now['base_extra'] ), 'Written whole: the files part new, the part activation as it was.' );
		$run = AB_MCP_IT_Process::calls( 'a', $plan( array( 'mode' => 'no_shutdown', 'nth' => 99 ), $first ) );
		self::assertSame( 'ab_mcp_staging_pair_changed', $run['results']['call0'], 'The step that read the old state writes nothing on the new one.' );
	}

	/* -------------------------------- B6: the rows of the series, ends */

	/**
	 * A stand-in of the calls of the series: each option by its name as the
	 * case says (null: not readable; a Throwable; a callable run before the
	 * real call), the rest real.
	 */
	private static function rows( array $plan ): object {
		return new class( $plan ) {
			private $plan;
			private $real;
			public function __construct( array $plan ) {
				$this->plan = $plan;
				$this->real = new AB_MCP_Sync_Env_Calls();
			}
			public function option( $name ) {
				$what = $this->plan[ $name ] ?? 'real';
				if ( 'null' === $what ) {
					return null;
				}
				if ( 'throw' === $what ) {
					throw new RuntimeException( 'rows gone' );
				}
				if ( is_callable( $what ) ) {
					$what();
				}
				return $this->real->option( $name );
			}
			public function attached( $after, $limit ) {
				return 'null' === ( $this->plan['attached'] ?? 'real' ) ? null : $this->real->attached( $after, $limit );
			}
		};
	}

	/** The lines of this site read whole through a stand-in of the rows, by kind:ref. */
	private static function read_with( array $plan ): array {
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Env::class, 'naht_sync_env', self::rows( $plan ) );
		try {
			$read = AB_MCP_Sync_Env::read( null, 60, 4000, 'live' );
		} finally {
			AB_MCP_Test_Seam::restore_all();
		}
		self::assertIsArray( $read );
		self::assertNull( $read['state'] );
		$out = array();
		foreach ( $read['lines'] as $line ) {
			$out[ $line['env'] . ':' . $line['ref'] ] = $line;
		}
		return $out;
	}

	public function testTheRowsOfTheSeriesThatFailOrChangeWhileReadAreNeverComparedHalf(): void {
		AB_MCP_IT_Code::start( array( 'a' ) );
		// A file of the uploads outside the file lists of the attachments.
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		$year    = $uploads . '/2098';
		self::assertFalse( file_exists( $year ) );
		mkdir( $year . '/01', 0755, true );
		$this->made[] = $year . '/01/frei.txt';
		file_put_contents( end( $this->made ), 'frei' );
		// A folder of version control below the uploads and at the top of the
		// must-use folder: each a unit, locked by unit() (A1).
		$mu   = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/mu-plugins';
		$made = ! is_dir( $mu );
		self::assertFalse( file_exists( $mu . '/.git' ) );
		mkdir( $year . '/.git' );
		mkdir( $mu . '/.git', 0755, true );
		$this->made[]  = $year . '/.git/HEAD';
		$this->made[]  = $mu . '/.git/HEAD';
		file_put_contents( $year . '/.git/HEAD', 'ref' );
		file_put_contents( $mu . '/.git/HEAD', 'ref' );
		$this->folders = array_merge( array( $year . '/01', $year . '/.git', $year, $mu . '/.git' ), $made ? array( $mu ) : array() );
		$whole = self::read_with( array() );
		self::assertSame( array( 'ab-it-allein.php' ), $whole['plugin:ab-it-allein']['active'], 'As it is, the main file running.' );
		foreach ( array( 'upload:2098/.git', 'mu_plugin:.git' ) as $key ) {
			self::assertSame( array( 'code:live_extra', 'vcs', null ), array( $whole[ $key ]['lock'], $whole[ $key ]['cause'], $whole[ $key ]['hash'] ), $key . ': locked, not read.' );
		}
		self::assertArrayNotHasKey( 'upload:2098/.git/HEAD', $whole, 'Nothing of it a unit of its own.' );
		self::assertIsString( $whole['theme_choice:theme_choice']['stylesheet'] );

		// Lesefehler: the row of the plugins that run cannot be read.
		$lines = self::read_with( array( 'active_plugins' => 'null' ) );
		self::assertNull( $lines['plugin:ab-it-allein']['active'], 'Not known which run: not «none runs».' );
		$e = AB_MCP_Sync_Env::compare( array( $whole['plugin:ab-it-allein'] ), array( $lines['plugin:ab-it-allein'] ) )['entries'];
		$e = array_values( array_filter( $e, static fn( $entry ) => 'plugin' === $entry['subtype'] ) )[0];
		self::assertSame( array( 'not_compared', 'Which main files run could not be read on this site.' ), array( $e['result'], $e['why'] ), 'Compared: not compared, with why.' );
		// The rows of the theme that runs: one cannot be read.
		$lines = self::read_with( array( 'stylesheet' => 'null' ) );
		self::assertNull( $lines['theme_choice:theme_choice']['stylesheet'] );
		// A switch of the theme while it is read (gleichzeitiger Schreiber):
		// no pair of rows that never stood together.
		$stylesheet = (string) $whole['theme_choice:theme_choice']['stylesheet'];
		$switched   = false;
		$lines      = self::read_with(
			array(
				'template' => static function () use ( &$switched ) {
					if ( ! $switched ) {
						$switched = true;
						global $wpdb;
						$wpdb->update( $wpdb->options, array( 'option_value' => 'ab-it-anders' ), array( 'option_name' => 'stylesheet' ) ); // phpcs:ignore WordPress.DB
					}
				},
			)
		);
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => $stylesheet ), array( 'option_name' => 'stylesheet' ) ); // phpcs:ignore WordPress.DB
		self::assertTrue( $switched );
		self::assertSame( array( null, null ), array( $lines['theme_choice:theme_choice']['stylesheet'], $lines['theme_choice:theme_choice']['template'] ), 'Read while it changed: not read.' );
		self::assertSame( $whole['theme_choice:theme_choice'], self::read_with( array() )['theme_choice:theme_choice'], 'Read again after the switch back: as before.' );
		// The file lists of the attachments cannot be read: no file of the
		// uploads is taken as outside them.
		self::assertSame( array( 'file', true ), array( $whole['upload:2098/01/frei.txt']['form'], $whole['upload:2098/01/frei.txt']['complete'] ) );
		$lines = self::read_with( array( 'attached' => 'null' ) );
		self::assertArrayNotHasKey( 'upload:2098/01/frei.txt', $lines );
		self::assertSame( array( 'root', false, 'attachments_unreadable' ), array( $lines['upload:*']['form'], $lines['upload:*']['complete'], $lines['upload:*']['cause'] ) );
		// A Throwable of a row: the call ends with it, no line of a half reading.
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Env::class, 'naht_sync_env', self::rows( array( 'stylesheet' => 'throw' ) ) );
		try {
			AB_MCP_Sync_Env::read( null, 60, 4000, 'live' );
			self::fail( 'The Throwable leaves the reading.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'rows gone', $e->getMessage() );
		} finally {
			AB_MCP_Test_Seam::restore_all();
		}
	}

	/** Every line of this site, read in parts of at most $max lines, by kind:ref, and the number of parts. */
	private static function read_in_parts( int $max ): array {
		$state = null;
		$parts = 0;
		$out   = array();
		do {
			$read = AB_MCP_Sync_Env::read( $state, 60, $max, 'live' );
			self::assertIsArray( $read );
			if ( $parts > 0 ) {
				// The first part holds the lines of the site at once (read()).
				self::assertLessThanOrEqual( $max, count( $read['lines'] ), 'A part holds at most its lines.' );
			}
			foreach ( $read['lines'] as $line ) {
				if ( 'page' !== $line['env'] ) {
					self::assertArrayNotHasKey( $line['env'] . ':' . $line['ref'], $out, 'No line twice.' );
					$out[ $line['env'] . ':' . $line['ref'] ] = $line;
				}
			}
			$state = $read['state'];
			++$parts;
		} while ( null !== $state && $parts < 500 );
		self::assertNull( $state );
		return array( $out, $parts );
	}

	public function testTheThemesOfAFurtherRootAreReadPageByPageNoneLeftOut(): void {
		// GitHub-Codex on free#147, P2: more themes of further roots than one
		// part holds go on in the next part, each with its lock.
		$root = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/ab-it-weitere-themes';
		self::assertFalse( file_exists( $root ) );
		$this->folders[] = $root;
		mkdir( $root );
		$names = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$name    = sprintf( 'ab-it-weit-%d', $i );
			$names[] = $name;
			mkdir( $root . '/' . $name );
			$this->made[] = $root . '/' . $name . '/style.css';
			file_put_contents( end( $this->made ), "/*\nTheme Name: Weit $i\nVersion: 1.$i\n*/\n" );
			array_splice( $this->folders, count( $this->folders ) - 1, 0, array( $root . '/' . $name ) );
		}
		$before                           = $GLOBALS['wp_theme_directories'];
		$GLOBALS['wp_theme_directories'][] = $root;
		try {
			list( $lines, $parts ) = self::read_in_parts( 3 );
		} finally {
			$GLOBALS['wp_theme_directories'] = $before;
		}
		self::assertGreaterThan( 3, $parts, 'Read over parts of three lines.' );
		foreach ( $names as $i => $name ) {
			self::assertArrayHasKey( 'theme:' . $name, $lines, $name . ': not left out.' );
			self::assertSame( array( 'code:theme_root', 'theme_root', null, '1.' . ( $i + 1 ) ), array( $lines[ 'theme:' . $name ]['lock'], $lines[ 'theme:' . $name ]['cause'], $lines[ 'theme:' . $name ]['hash'], $lines[ 'theme:' . $name ]['version'] ) );
		}
	}

	public function testAFileOfAnAttachmentAtTheTopOfTheUploadsIsNoUnit(): void {
		// GitHub-Codex on free#147, P2: a site without year and month folders
		// keeps the files of its attachments at the top of the uploads.
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		if ( ! is_dir( $uploads ) ) {
			mkdir( $uploads );
			$this->folders[] = $uploads;
		}
		foreach ( array( 'ab-it-oben.jpg', 'ab-it-frei-oben.txt' ) as $file ) {
			self::assertFalse( file_exists( $uploads . '/' . $file ) );
			$this->made[] = $uploads . '/' . $file;
			self::assertNotFalse( file_put_contents( end( $this->made ), 'bytes of ' . $file ) );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Oben',
				'post_status'    => 'inherit',
			),
			$uploads . '/ab-it-oben.jpg'
		);
		self::assertGreaterThan( 0, $id );
		try {
			self::assertSame( 'ab-it-oben.jpg', get_post_meta( $id, '_wp_attached_file', true ) );
			$lines = self::read_with( array() );
		} finally {
			wp_delete_post( $id, true );
		}
		self::assertArrayNotHasKey( 'upload:ab-it-oben.jpg', $lines, 'In the file list of an attachment: no unit, also at the top.' );
		self::assertSame( array( 'file', true ), array( $lines['upload:ab-it-frei-oben.txt']['form'], $lines['upload:ab-it-frei-oben.txt']['complete'] ), 'Outside every file list: a unit.' );
	}

	public function testAlphaBridgeItselfInTheMustUseFolderIsReadWithItsVersion(): void {
		// GitHub-Codex on ed1e512, P2: AlphaBridge itself as a must-use unit
		// (here a link to this plugin, its identity) shows its version.
		$mu = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/mu-plugins';
		if ( ! is_dir( $mu ) ) {
			mkdir( $mu );
			$this->folders[] = $mu;
		}
		$link = $mu . '/ab-it-selbst';
		self::assertFalse( file_exists( $link ) || is_link( $link ) );
		$this->made[] = $link;
		symlink( rtrim( AB_MCP_DIR, '/' ), $link );
		self::assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( AB_MCP_DIR . 'alphabridge-mcp.php' ), $m ) );
		$line = self::read_with( array() )['mu_plugin:ab-it-selbst'];
		self::assertSame( array( 'own', true, $m[1], null ), array( $line['form'], $line['own'], $line['version'], $line['hash'] ), 'Shown by its version, not hashed.' );
	}

	public function testAnEndOfTheProcessOrItsTimeLimitWhileTheSeriesReadsLeavesNothing(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		$plan = static function ( string $owner, string $property, string $real, string $method, string $mode ): array {
			return array(
				'require' => array( AB_MCP_DIR . 'includes/sync/class-sync-export.php' ),
				'seams'   => array(
					array(
						'owner'    => $owner,
						'property' => $property,
						'real'     => $real,
						'faults'   => array(
							array(
								'method' => $method,
								'nth'    => 1,
								'kind'   => 'abort',
								'point'  => $method,
								'mode'   => $mode,
							),
						),
					),
				),
				'calls'   => array(
					array(
						'call' => 'AB_MCP_Sync_Export::make',
						'args' => array( 'env' ),
					),
				),
			);
		};
		$before = self::store_files();
		foreach ( array( 'no_shutdown', 'timeout' ) as $mode ) {
			// A row of the series (the language, the first row it reads).
			AB_MCP_IT_Process::abort( 'b', $plan( 'AB_MCP_Sync_Env', 'naht_sync_env', 'AB_MCP_Sync_Env_Calls', 'option', $mode ) );
			self::assertSame( $before, self::store_files(), "A row of the series, $mode: nothing left in the store of the staging site." );
			// The request for the checksums of the core (HTTP).
			AB_MCP_IT_Process::abort( 'b', $plan( 'AB_MCP_Sync_Core_Compare', 'naht_sync_core_compare', 'AB_MCP_Sync_Core_Compare_Calls', 'get', $mode ) );
			self::assertSame( $before, self::store_files(), "The request of the checksums, $mode: nothing left." );
		}
		$env = self::report();
		self::assertSame( array( 'equal', 'none', array() ), self::said( $env['plugin:ab-it-basis'] ), 'The next reading is whole.' );
		self::assertSame( array( 'equal', 'none', array() ), self::said( $env['theme_choice:theme_choice'] ) );
	}

	/** Every file of the protected stores of both sites (beside their folders), by path. */
	private static function store_files(): array {
		$root  = (string) AB_MCP_IT_Sites::state()['root'] . '/ab-mcp-store';
		$files = array();
		if ( is_dir( $root ) ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				$files[] = substr( (string) $file, strlen( $root ) );
			}
		}
		sort( $files );
		return $files;
	}

	/* --------------------------------------------------------- Z35 */

	public function testTheCoreFilesOfThisSiteAreCheckedAgainstTheStoredChecksums(): void {
		$body  = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/core-checksums/7.1.3-en_US.json' );
		$calls = new class( $body ) {
			private $body;
			private $real;
			public $asked = array();
			public function __construct( $body ) {
				$this->body = $body;
				$this->real = new AB_MCP_Sync_Core_Compare_Calls();
			}
			public function get( $url, array $args ) {
				$this->asked[] = $url;
				return array(
					'headers'  => array(),
					'body'     => $this->body,
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				);
			}
			public function md5( $path ) {
				return $this->real->md5( $path );
			}
			public function exists( $path ) {
				return $this->real->exists( $path );
			}
		};
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Core_Compare::class, 'naht_sync_core_compare', $calls );
		$check = AB_MCP_Sync_Core_Compare::check( INF );
		self::assertSame( array( 'cause' => null, 'checked' => 3338, 'complete' => true, 'differs' => 0, 'missing' => 0 ), $check, 'WordPress 7.1.3 of the test site as shipped.' );
		self::assertSame( array( 'https://api.wordpress.org/core/checksums/1.0/?version=7.1.3&locale=en_US' ), $calls->asked );
		$file  = ABSPATH . 'readme.html';
		$bytes = (string) file_get_contents( $file );
		try {
			file_put_contents( $file, $bytes . ' ' );
			$check = AB_MCP_Sync_Core_Compare::check( INF );
			self::assertSame( array( 1, 0 ), array( $check['differs'], $check['missing'] ), 'F17: one core file changed here.' );
		} finally {
			file_put_contents( $file, $bytes );
		}
	}

	public function testATreeOfEmptyFoldersIsWalkedOverPartsWithItsPlaceAndNoUnitLeftOut(): void {
		// GitHub-Codex on e9db02d: a tree of language files with many empty
		// folders (and so a large media library with many files of
		// attachments) gives no unit for a long walk; the time of the call
		// ends it there too, with the place of the last entry, and the next
		// call goes on after it. The clock: a second a look.
		$lang = rtrim( (string) WP_LANG_DIR, '/' );
		if ( ! is_dir( $lang ) ) {
			mkdir( $lang );
			$made_lang = true;
		}
		$tree = $lang . '/ab-it-leer';
		self::assertFalse( file_exists( $tree ) );
		mkdir( $tree );
		$folders = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$folders[] = sprintf( '%s/%03d', $tree, $i );
			mkdir( end( $folders ) );
		}
		$this->made[]  = $tree . '/zz.mo';
		file_put_contents( end( $this->made ), 'mo' );
		// And at the top of the uploads many folders that are no year folder
		// (each skipped: counted with the root, no unit), then a free file.
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		if ( ! is_dir( $uploads ) ) {
			mkdir( $uploads );
			$made_uploads = true;
		}
		$skipped = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$skipped[] = sprintf( '%s/ab-it-cache-%03d', $uploads, $i );
			mkdir( end( $skipped ) );
		}
		$this->made[]  = $uploads . '/zz-ab-it-frei.txt';
		file_put_contents( end( $this->made ), 'frei' );
		$this->folders = array_merge( array_reverse( $folders ), array( $tree ), empty( $made_lang ) ? array() : array( $lang ), $skipped, empty( $made_uploads ) ? array() : array( $uploads ) );
		$t                      = 0.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
			return ++$t;
		};
		$steps  = array(
			'language' => array_search( 'language', AB_MCP_Sync_Env::STEPS, true ),
			'upload'   => array_search( 'upload', AB_MCP_Sync_Env::STEPS, true ),
		);
		$state  = null;
		$parts  = 0;
		$walked = array(
			'language' => 0,
			'upload'   => 0,
		);
		$units  = array();
		do {
			$read = AB_MCP_Sync_Env::read( $state, 5, 4000, 'live' );
			self::assertIsArray( $read );
			$kinds = array();
			foreach ( $read['lines'] as $line ) {
				if ( isset( $steps[ $line['env'] ] ) ) {
					$units[]                = $line['env'] . ':' . $line['ref'];
					$kinds[ $line['env'] ] = true;
				}
			}
			$state = $read['state'];
			foreach ( $steps as $kind => $index ) {
				if ( null !== $state && $index === $state['step'] && is_array( $state['after'] ) && ! isset( $kinds[ $kind ] ) ) {
					++$walked[ $kind ];
				}
			}
			++$parts;
		} while ( null !== $state && $parts < 300 );
		self::assertNull( $state, 'Read to its end.' );
		self::assertGreaterThan( 0, $walked['language'], 'Parts that ended within the walk of the empty folders, with its place, no unit yet.' );
		self::assertGreaterThan( 0, $walked['upload'], 'Parts that ended within the walk of the skipped folders of the uploads, with its place.' );
		foreach ( array( 'language:ab-it-leer/zz.mo', 'upload:zz-ab-it-frei.txt' ) as $unit ) {
			self::assertSame( 1, count( array_keys( $units, $unit, true ) ), "$unit after them read once: none left out, none twice." );
		}
		self::assertSame( count( $units ), count( array_unique( $units ) ), 'No unit twice.' );
	}

	public function testACoreTooSlowForAWholeCallIsNotComparedAndTheRestOfTheEnvironmentIsRead(): void {
		// GitHub-Codex on ec0d088 (G-M7): a host where the core files take
		// longer than UNIT_BUDGET (here 1 s a file). With the whole time of a
		// call the line says not compared (too_slow) and the series goes on.
		$body = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/core-checksums/7.1.3-en_US.json' );
		$t    = 0.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
			return $t;
		};
		$tick  = static function () use ( &$t ) {
			$t += 1.0;
		};
		$calls = new class( $body, $tick ) {
			private $body;
			private $tick;
			private $real;
			public $md5 = 0;
			public function __construct( $body, $tick ) {
				$this->body = $body;
				$this->tick = $tick;
				$this->real = new AB_MCP_Sync_Core_Compare_Calls();
			}
			public function get( $url, array $args ) {
				return array(
					'headers'  => array(),
					'body'     => $this->body,
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				);
			}
			public function md5( $path ) {
				++$this->md5;
				( $this->tick )();
				return $this->real->md5( $path );
			}
			public function exists( $path ) {
				return $this->real->exists( $path );
			}
		};
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Core_Compare::class, 'naht_sync_core_compare', $calls );
		$state = null;
		$parts = 0;
		$core  = array();
		do {
			$read = AB_MCP_Sync_Env::read( $state, 60, 4000, 'live' );
			self::assertIsArray( $read );
			foreach ( $read['lines'] as $line ) {
				if ( 'core_files' === $line['env'] ) {
					$core[] = $line;
				}
			}
			$state = $read['state'];
			++$parts;
		} while ( null !== $state && $parts < 3 );
		self::assertNull( $state, 'The environment read to its end within three calls, not held at the core.' );
		self::assertCount( 1, $core, 'One line of the core files.' );
		self::assertSame( array( 'too_slow', false ), array( $core[0]['cause'], $core[0]['complete'] ), 'Not compared, with why: never equal (Z35).' );
		self::assertSame( 63, $calls->md5, 'The core hashed once, as far as its time reached; not again in every call.' );
	}

	/* ----------------------------------- exit in a process of its own */

	public function testAnEndOfTheProcessWhileAUnitIsHashedLeavesNoHalfReading(): void {
		self::pair();
		AB_MCP_IT_Code::start();
		$plan = array(
			'require' => array( AB_MCP_DIR . 'includes/sync/class-sync-export.php' ),
			'seams'   => array(
				array(
					'owner'    => 'AB_MCP_Sync_File_Manifest',
					'property' => 'naht_sync_file_manifest',
					'real'     => 'AB_MCP_Sync_File_Manifest_Calls',
					'faults'   => array(
						array(
							'method' => 'read',
							'nth'    => 3,
							'kind'   => 'abort',
							'point'  => 'hash',
							'mode'   => 'no_shutdown',
						),
					),
				),
			),
			'calls'   => array(
				array(
					'call' => 'AB_MCP_Sync_Export::make',
					'args' => array( 'env' ),
				),
			),
		);
		$before = self::store_files();
		AB_MCP_IT_Process::abort( 'b', $plan );
		self::assertSame( $before, self::store_files(), 'No package of a half reading on the staging site, nothing left in the store.' );
		$env = self::report();
		self::assertSame( array( 'equal', 'none', array() ), self::said( $env['plugin:ab-it-basis'] ), 'The next reading is whole.' );
	}
}
