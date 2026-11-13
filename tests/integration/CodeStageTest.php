<?php
/**
 * Z8b branch 4 with two sites (plan 9.1, 9.2: the part of this branch of F1,
 * F2, F3, F4, F19, F20, F43; 3.1 Z4 early, Z5, Z6): B is the staging site of
 * A; a plugin of B in a new version is staged on A, checked and «ready», and
 * nothing of A changes. The source is wordpress.org in the exact version (the
 * stand-in for downloads.wordpress.org, AB_MCP_Test_Wporg, through the seam
 * of the stage) or packages of code of B, relayed, with only the files A
 * lacks; one unit over several packages; the refusals of the check.
 *
 * Fehlerarten per effect to the outside (plan 8.3, branch 4), where this
 * suite holds them:
 * - HTTP (the download): a version wordpress.org does not keep (404), a
 *   ZIP that is not the version of the staging site, the file system not
 *   direct (testTheSourceWordpressOrgIsTakenOnlyAsTheSignedUnit); exit/Fatal
 *   in a process of its own (testAnEndOfTheProcessWhileStagingLeavesNothingReady);
 *   false, a Throwable and the time limit of the transport, and a body cut at
 *   the limit in the unit suite (SyncCodeStageTest).
 * - Files of the stage (the building folder and the tree): an end of the
 *   process leaves nothing ready (above); a tree changed in the store is
 *   never ready (testAStageWhoseTreeOrStateChangedIsNeverReady); a building
 *   folder past TMP_AGE goes with what is in it, a young one stays
 *   (testABuildingFolderGoesOnlyByItsAgeOrByItsOwnRequest); a file of the
 *   live unit that comes out otherwise while it is copied (halb geschrieben)
 *   stages nothing (testAUnitBuiltFromPackagesThatIsNotTheSignedOneIsNotStaged);
 *   a tree that does not read whole within its time (Zeitlimit, Lesefehler)
 *   is never ready (testAStageThatDoesNotReadWholeForItsCheckIsNeverReady).
 *   A gleichzeitiger Schreiber of the building folder: none, it is made and
 *   used by one request under its own name.
 * - Rows of the state of packages and relay (the index of a package of code,
 *   the state of a stage): every wrong form of the rows code.index and
 *   stage.state reaches its consumer as unknown (StoreInputsTest, unit); a
 *   chunk that does not inflate when a package is read is refused
 *   (testAChunkThatDoesNotInflateWhenAPackageIsReadIsRefusedNeverReadAsNoLines,
 *   V4); a package without the files a stage needs stages nothing
 *   (testOneUnitOverSeveralPackagesIsStagedOnlyWithAllOfThem). The relay
 *   itself (a chunk twice, a chunk of another hash) is the import of Z8,
 *   unchanged (tests/integration/SyncPackageTest.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Code;
use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_IT_Process;
use AB_MCP_Protected_Store;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Code_Stage;
use AB_MCP_Sync_Code_Stage_Calls;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AB_MCP_Test_Wporg;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-code-stage.php';
require_once __DIR__ . '/support/class-test-wporg.php';

final class CodeStageTest extends TestCase {

	private const SEAM = 'naht_sync_code_stage';

	private const RUN = 7;

	private const REPORT = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

	/** @var string|null */
	private $docroot_before;

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
		AB_MCP_IT_Code::start();
		self::pair();
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Code_Stage::$clock = null;
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_IT_Code::clear();
		AB_MCP_Protected_Store::forget();
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

	private static function root(): string {
		return (string) AB_MCP_IT_Sites::state()['root'];
	}

	private static function wipe(): void {
		$store = self::root() . '/ab-mcp-store';
		if ( is_dir( $store ) ) {
			self::remove_tree( $store );
		}
	}

	private static function remove_tree( string $path ): void {
		if ( 0 !== strpos( $path, self::root() . '/' ) ) {
			throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
		}
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
			return;
		}
		chmod( $path, 0700 );
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry );
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

	/** A package B makes with these arguments, taken over by A over the relay: its id and the answer of B. */
	private static function relayed( array $export, ?array $import = null ): array {
		$package = self::b( array( array( 'op' => 'export', 'as' => 'package' ) + $export ) )['package'];
		self::assertIsArray( $package );
		self::assertArrayHasKey( 'package_id', $package, var_export( $package, true ) );
		$state = AB_MCP_Sync_Import::call(
			( null === $import ? array() : $import ) + array(
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

	/** The environment of B, a whole series taken over by A: the package ids. */
	private static function environment(): array {
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
		$ids    = array();
		$cursor = null;
		do {
			list( $id, $package ) = self::relayed( array( 'purpose' => 'env' ) + ( null === $cursor ? array() : array( 'cursor' => $cursor ) ) );
			$cursor               = $package['cursor'];
			$ids[]                = $id;
		} while ( null !== $cursor && count( $ids ) < 50 );
		return $ids;
	}

	/** A package of code of B for these units (kind, ref, paths), taken over by A. */
	private static function code( array $refs ): array {
		return self::relayed(
			array(
				'purpose'     => 'code',
				'report_hash' => self::REPORT,
				'refs'        => $refs,
			)
		);
	}

	/** The seam of the stage with the stand-in of wordpress.org. */
	private static function wporg( ?array $offer = null ): CodeStageWporgCalls {
		$calls = new CodeStageWporgCalls( new AB_MCP_Test_Wporg( $offer ) );
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Stage::class, self::SEAM, $calls );
		return $calls;
	}

	/** complete() until it is not checking any more. */
	private static function checked( array $stage, array $env ): array {
		for ( $i = 0; $i < 50; $i++ ) {
			$state = AB_MCP_Sync_Code_Stage::complete( $stage['gid'], $stage['name'], $stage['hash'], $env );
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_code() . ': ' . $state->get_error_message() : '' );
			if ( 'checking' !== $state['state'] ) {
				return $state;
			}
		}
		throw new RuntimeException( 'The check did not end.' );
	}

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

	/* -------------------------------------------------------- normal case */

	public function testAPluginOfWordpressOrgInANewerVersionIsStagedCheckedAndReadyWithoutAChangeOfTheSite(): void {
		// F1 and F2, the part of this branch: the source wordpress.org in the
		// exact version of the staging site, checked against its signed manifest.
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$env    = self::environment();
		$before = self::live_bytes( 'ab-it-inaktiv' );
		$need   = AB_MCP_Sync_Code_Stage::need( $env, 'plugin', 'ab-it-inaktiv' );
		self::assertIsArray( $need );
		self::assertFalse( $need['same'] );
		self::assertNotSame( array(), $need['paths'] );
		$calls = self::wporg();
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' );
		self::assertIsArray( $stage, is_wp_error( $stage ) ? $stage->get_error_code() . ': ' . $stage->get_error_message() : '' );
		self::assertSame( 'wordpress.org', $stage['source'] );
		self::assertSame( array( 'https://downloads.wordpress.org/plugin/ab-it-inaktiv.1.1.0.zip' ), array_column( $calls->wporg->calls, 'url' ), 'The exact address, once.' );
		self::assertSame( 0, $calls->wporg->calls[0]['args']['redirection'] );
		self::assertNull( AB_MCP_Sync_Code_Stage::ready( $stage['gid'], $stage['name'], $stage['hash'] ), 'Not ready before complete().' );
		$state = self::checked( $stage, $env );
		self::assertSame( 'ready', $state['state'], var_export( $state, true ) );
		self::assertGreaterThan( 0, $state['files'], 'Its PHP files were checked.' );
		self::assertSame( $state['files'], $state['checked'] );
		self::assertIsArray( AB_MCP_Sync_Code_Stage::ready( $stage['gid'], $stage['name'], $stage['hash'] ) );
		$tree = AB_MCP_Protected_Store::tree( 'stage', $stage['gid'], $stage['name'], $stage['hash'] );
		self::assertIsArray( $tree );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the live unit changed.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
		self::assertSame( array(), glob( self::root() . '/ab-mcp-store/*/tmp/.ab-tmp-*' ), 'The building folder went.' );
		// A unit live has bytewise needs no stage.
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		self::assertTrue( AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' )['same'] );
	}

	public function testAnOwnPluginComesAsAPackageOfCodeWithOnlyTheFilesTheLiveUnitLacks(): void {
		// F3 and F4, the part of this branch: an own plugin (no source on
		// wordpress.org), three files changed in the same version: the package
		// holds exactly those three, the rest (a file the same on both sites)
		// comes from the live unit.
		$b = AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-daten', '1.0.0' );
		foreach ( array( AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-daten' ), $b ) as $dir ) {
			file_put_contents( $dir . '/bleibt.txt', "auf beiden Seiten gleich\n" );
		}
		foreach ( array( 'ab-it-daten.php', 'data/export.csv', 'neu/eins.php' ) as $i => $rel ) {
			if ( ! is_dir( dirname( $b . '/' . $rel ) ) ) {
				mkdir( dirname( $b . '/' . $rel ), 0755, true );
			}
			file_put_contents( $b . '/' . $rel, ( is_file( $b . '/' . $rel ) ? (string) file_get_contents( $b . '/' . $rel ) : "<?php\n" ) . "\n// geändert $i\n" );
		}
		$env  = self::environment();
		$need = AB_MCP_Sync_Code_Stage::need( $env, 'plugin', 'ab-it-daten' );
		sort( $need['paths'] );
		self::assertSame( array( 'ab-it-daten.php', 'data/export.csv', 'neu/eins.php' ), $need['paths'] );
		// wordpress.org has no such plugin: from_org() fails, the way is a package.
		self::wporg( array() );
		self::assertSame( 'code:org_fetch', AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten' )->get_error_code() );
		list( $id, $package ) = self::code( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-daten', 'paths' => $need['paths'] ) ) );
		self::assertSame( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-daten', 'files' => 3 ) ), $package['code']['packed'], 'The package holds exactly the three files.' );
		self::assertSame( array( array( 'complete' => true, 'kind' => 'plugin', 'subtype' => 'ab-it-daten' ) ), $package['kinds'] );
		$taken = AB_MCP_Sync_Import::taken( $id, 'code' );
		self::assertCount( 3, $taken['files'] );
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( $id ) );
		self::assertIsArray( $stage, is_wp_error( $stage ) ? $stage->get_error_code() . ': ' . $stage->get_error_message() : '' );
		self::assertSame( 'packages', $stage['source'] );
		self::assertSame( 'ready', self::checked( $stage, $env )['state'] );
		self::assertSame( "auf beiden Seiten gleich\n", file_get_contents( AB_MCP_Protected_Store::tree( 'stage', $stage['gid'], $stage['name'], $stage['hash'] )['path'] . '/bleibt.txt' ), 'The file the package does not hold comes from the live unit.' );
		// A path the unit on the staging site does not hold, beside one it
		// holds: nothing of the unit is packed.
		$none = self::b( array( array( 'op' => 'export', 'purpose' => 'code', 'report_hash' => self::REPORT, 'refs' => array( array( 'kind' => 'plugin', 'ref' => 'ab-it-daten', 'paths' => array( 'ab-it-daten.php', 'gibt-es-nicht.php' ) ) ), 'expect' => 'error', 'as' => 'x' ) ) )['x'];
		self::assertSame( 'ab_mcp_sync_export_code_none', $none['code'] );
		// Without the package: the files it lacks are named, nothing staged.
		$missing = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( 'f' . substr( $id, 1 ) ) );
		self::assertInstanceOf( \WP_Error::class, $missing );
	}

	public function testAlphaBridgeItselfGoesIntoNoPackageOfCode(): void {
		// code_unit(): AlphaBridge itself is shown, never packed (E12), by the
		// identity of its path; the other units of the same call are packed.
		$b = AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-daten', '1.0.0' );
		file_put_contents( $b . '/neu.php', "<?php\n// neu\n" );
		list( , $package ) = self::code(
			array(
				array(
					'kind' => 'plugin',
					'ref'  => 'alphabridge-mcp',
				),
				array(
					'kind'  => 'plugin',
					'ref'   => 'ab-it-daten',
					'paths' => array( 'neu.php' ),
				),
			)
		);
		self::assertSame(
			array(
				array(
					'kind'    => 'plugin',
					'ref'     => 'alphabridge-mcp',
					'problem' => 'own',
				),
			),
			$package['code']['unread']
		);
		self::assertSame( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-daten', 'files' => 1 ) ), $package['code']['packed'] );
	}

	public function testOneUnitOverSeveralPackagesIsStagedOnlyWithAllOfThem(): void {
		// F43, the part of this branch: more than one package holds (here the
		// lines of a manifest, 5000 objects), the rest in the next package; the
		// stage only with every package of it.
		$b = AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-allein', '1.1.0' );
		mkdir( $b . '/viele', 0755 );
		for ( $i = 0; $i < 5001; $i++ ) {
			file_put_contents( $b . '/viele/f' . $i . '.txt', (string) $i );
		}
		$env  = self::environment();
		$need = AB_MCP_Sync_Code_Stage::need( $env, 'plugin', 'ab-it-allein' );
		self::assertGreaterThan( 5000, count( $need['paths'] ) );
		list( $first, $package ) = self::code( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-allein', 'paths' => $need['paths'] ) ) );
		self::assertSame( 5000, $package['objects'] );
		self::assertFalse( $package['kinds'][0]['complete'], 'Not every file of the unit in this package.' );
		self::assertCount( 1, $package['code']['left'] );
		$left  = $package['code']['left'][0]['paths'];
		self::assertSame( count( $need['paths'] ) - 5000, count( $left ) );
		self::assertSame( 'code:stage_incomplete', AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-allein', array( $first ) )->get_error_code(), 'Not with the first package alone.' );
		list( $second ) = self::code( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-allein', 'paths' => $left ) ) );
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-allein', array( $first, $second ) );
		self::assertIsArray( $stage, is_wp_error( $stage ) ? $stage->get_error_message() : '' );
		self::assertSame( 'ready', self::checked( $stage, $env )['state'] );
	}

	public function testAUnitBuiltFromPackagesThatIsNotTheSignedOneIsNotStaged(): void {
		// from_packages(), the one place for this source: the unit built from
		// the packages and the live unit counts only where its manifest is
		// the signed one; a file of the live unit that comes out otherwise
		// while it is copied stages nothing.
		$b = AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-daten', '1.0.0' );
		file_put_contents( $b . '/neu.php', "<?php\n// neu\n" );
		$env   = self::environment();
		$calls = self::wporg( array() );
		list( $id ) = self::code( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-daten', 'paths' => array( 'neu.php' ) ) ) );
		$calls->corrupt = true;
		$stage          = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( $id ) );
		self::assertInstanceOf( \WP_Error::class, $stage );
		self::assertSame( 'code:stage_mismatch', $stage->get_error_code() );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'stage' ), 'Nothing went into the area stage.' );
		$calls->corrupt = false;
		self::assertIsArray( AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( $id ) ), 'The same packages stage where the copy is whole.' );
	}

	/* ---------------------------------------------------- refusals (F19, F20) */

	public function testTheCheckRefusesWhatDoesNotParseCompileOrFitThisSite(): void {
		// F19 and F20 (code:requirements), each through complete(), never ready.
		$b = AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-daten', '1.0.0' );
		file_put_contents( $b . '/kaputt.php', "<?php\nfunction ( {\n" );
		$env = self::environment();
		self::wporg( array() );
		list( $id ) = self::code( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-daten', 'paths' => array( 'kaputt.php' ) ) ) );
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( $id ) );
		self::assertIsArray( $stage );
		$state = self::checked( $stage, $env );
		self::assertSame( 'refused', $state['state'] );
		self::assertSame( 'code:syntax', $state['refusal']['code'] );
		self::assertSame( 'kaputt.php', $state['refusal']['path'] );
		self::assertSame( 2, $state['refusal']['line'] );
		self::assertNull( AB_MCP_Sync_Code_Stage::ready( $stage['gid'], $stage['name'], $stage['hash'] ) );
		self::assertSame( 'refused', AB_MCP_Sync_Code_Stage::complete( $stage['gid'], $stage['name'], $stage['hash'], $env )['state'], 'A stage refused stays refused.' );
		// The parser alone, where no command line is there: the same refusal.
		$calls       = self::wporg( array() );
		$calls->lint = false;
		$alone       = self::checked( AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( $id ) ), $env );
		self::assertSame( 'code:syntax', $alone['refusal']['code'] );
		self::assertStringContainsString( 'does not parse', $alone['refusal']['message'] );
	}

	public function testAFileTheParserPassesButPhpRefusesToCompileIsRefused(): void {
		// F19: break outside a loop parses and does not compile (php -l, a
		// command line of the same version: this test process runs on one).
		$b = AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-daten', '1.0.0' );
		file_put_contents( $b . '/ausserhalb.php', "<?php\nbreak;\n" );
		$env = self::environment();
		self::wporg( array() );
		list( $id ) = self::code( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-daten', 'paths' => array( 'ausserhalb.php' ) ) ) );
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( $id ) );
		$state = self::checked( $stage, $env );
		self::assertSame( 'refused', $state['state'], var_export( $state, true ) );
		self::assertSame( 'code:syntax', $state['refusal']['code'] );
		self::assertSame( 'ausserhalb.php', $state['refusal']['path'] );
		self::assertStringContainsString( 'php -l', $state['refusal']['message'] );
	}

	public function testNoTokenizerOrAFileOverTheLimitIsNeverReady(): void {
		// F19: without a tokenizer code:syntax_unavailable; a file over 1 MiB
		// code:syntax_unchecked (nothing shows the same bytes ran on staging, D6).
		$b = AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-daten', '1.0.0' );
		file_put_contents( $b . '/gross.php', "<?php\n// " . str_repeat( 'x', AB_MCP_Sync_Code_Stage::PARSE_MAX_BYTES ) . "\n" );
		$env = self::environment();
		self::wporg( array() );
		list( $id ) = self::code( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-daten', 'paths' => array( 'gross.php' ) ) ) );
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( $id ) );
		$state = self::checked( $stage, $env );
		self::assertSame( 'code:syntax_unchecked', $state['refusal']['code'] );
		$calls            = self::wporg( array() );
		$calls->tokenizer = false;
		$again            = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-daten', array( $id ) );
		self::assertSame( 'code:syntax_unavailable', self::checked( $again, $env )['refusal']['code'] );
	}

	public function testANewVersionThatNeedsANewerPhpIsRefused(): void {
		// F20, the part of this branch: Requires PHP 99.0 (code:requirements).
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-aktiv', 'php' );
		$env   = self::environment();
		self::wporg();
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-aktiv' );
		self::assertIsArray( $stage, is_wp_error( $stage ) ? $stage->get_error_message() : '' );
		$state = self::checked( $stage, $env );
		self::assertSame( 'code:requirements', $state['refusal']['code'] );
		self::assertStringContainsString( '99.0', $state['refusal']['message'] );
	}

	public function testTheSourceWordpressOrgIsTakenOnlyAsTheSignedUnit(): void {
		// Z5: another ZIP under that version (the stand-in offers another
		// version's bytes), a version wordpress.org does not keep, a head with
		// another host for its updates (D1), no direct file system (D5).
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$env = self::environment();
		self::wporg( array( 'plugin' => array( 'ab-it-inaktiv' => array( '1.1.0' => '1.0.0' ) ) ) );
		self::assertSame( 'code:org_mismatch', AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' )->get_error_code() );
		self::wporg( array( 'plugin' => array( 'ab-it-inaktiv' => array( '1.0.0' => '1.0.0' ) ) ) );
		self::assertSame( 'code:org_fetch', AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' )->get_error_code() );
		$calls         = self::wporg();
		$calls->method = 'ftpext';
		self::assertSame( 'code:file_mods', AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' )->get_error_code() );
		self::assertSame( array(), $calls->wporg->calls, 'Nothing fetched without the direct file system.' );
		// A head that names another host for its updates: nothing fetched.
		$b = AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-inaktiv' );
		file_put_contents( $b . '/ab-it-inaktiv.php', str_replace( "Version:", "Update URI: https://updates.example.com/inaktiv\n * Version:", (string) file_get_contents( $b . '/ab-it-inaktiv.php' ) ) );
		$env   = self::environment();
		$calls = self::wporg();
		$got   = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' );
		self::assertSame( 'code:update_uri', $got->get_error_code() );
		self::assertStringContainsString( 'updates.example.com', $got->get_error_message() );
		self::assertSame( array(), $calls->wporg->calls );
		self::assertSame( array(), glob( self::root() . '/ab-mcp-store/*/tmp/.ab-tmp-*' ), 'No building folder stays.' );
		// Two main files, one with wordpress.org and one with another host: the
		// unit names another host (D1).
		$zwei = AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-zwei' );
		file_put_contents( $zwei . '/ab-it-zwei-eins.php', str_replace( 'Version:', "Update URI: w.org/plugin/ab-it-zwei\n * Version:", (string) file_get_contents( $zwei . '/ab-it-zwei-eins.php' ) ) );
		file_put_contents( $zwei . '/ab-it-zwei-zwei.php', str_replace( 'Version:', "Update URI: https://zwei.example.com/u\n * Version:", (string) file_get_contents( $zwei . '/ab-it-zwei-zwei.php' ) ) );
		self::assertSame( 'zwei.example.com', AB_MCP_Sync_Code_Stage::signed( self::environment(), 'plugin', 'ab-it-zwei' )['update_uri'] );
	}

	public function testAStageWhoseTreeOrStateChangedIsNeverReady(): void {
		// Z4 early and Z6: the tree changed in the store, or the stage of
		// another unit named, or a state that is not of its form.
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$env   = self::environment();
		self::wporg();
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' );
		self::assertIsArray( $stage );
		$other = AB_MCP_Sync_Code_Stage::complete( $stage['gid'], $stage['name'], str_repeat( 'a', 64 ), $env );
		self::assertSame( 'code:stage_unknown', $other->get_error_code() );
		// Z4 early: the staging site changed the unit since; with the report
		// of now the stage is not its unit, and never ready.
		file_put_contents( AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-inaktiv' ) . '/neu.php', "<?php // neu\n" );
		$changed = AB_MCP_Sync_Code_Stage::complete( $stage['gid'], $stage['name'], $stage['hash'], self::environment() );
		self::assertSame( 'refused', $changed['state'] );
		self::assertSame( 'code:stage_mismatch', $changed['refusal']['code'] );
		self::assertNull( AB_MCP_Sync_Code_Stage::ready( $stage['gid'], $stage['name'], $stage['hash'] ) );
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' );
		self::assertIsArray( $stage );
		$tree = AB_MCP_Protected_Store::tree( 'stage', $stage['gid'], $stage['name'], $stage['hash'] );
		file_put_contents( $tree['path'] . '/ab-it-inaktiv.php', "<?php // anders\n", FILE_APPEND );
		$got = AB_MCP_Sync_Code_Stage::complete( $stage['gid'], $stage['name'], $stage['hash'], $env );
		self::assertSame( 'ab_mcp_store_tree_changed', $got->get_error_code() );
		self::assertNull( AB_MCP_Sync_Code_Stage::ready( $stage['gid'], $stage['name'], $stage['hash'] ) );
	}

	public function testAnEndOfTheProcessWhileStagingLeavesNothingReady(): void {
		// exit/Fatal in a process of its own, in the download of the package
		// of wordpress.org: the building folder of that request stays (no
		// shutdown), nothing went into stage, the site is unchanged; the
		// clean-up takes the folder past TMP_AGE (D4).
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$env    = self::environment();
		$signed = AB_MCP_Sync_Code_Stage::signed( $env, 'plugin', 'ab-it-inaktiv' );
		self::assertIsArray( $signed );
		$before = self::live_bytes( 'ab-it-inaktiv' );
		$run    = AB_MCP_IT_Process::abort(
			'a',
			array(
				'require' => array( AB_MCP_DIR . 'includes/sync/class-sync-code-stage.php' ),
				'seams'   => array(
					array(
						'owner'    => 'AB_MCP_Sync_Code_Stage',
						'property' => self::SEAM,
						'real'     => 'AB_MCP_Sync_Code_Stage_Calls',
						'faults'   => array(
							array(
								'method' => 'get',
								'kind'   => 'abort',
								'point'  => 'download',
								'mode'   => 'no_shutdown',
							),
						),
					),
				),
				'calls'   => array(
					array(
						'call' => 'AB_MCP_Protected_Store::build_dir',
						'as'   => 'build',
					),
					array(
						'call' => 'AB_MCP_Sync_Code_Stage::from_org',
						'args' => array( $signed, '$build' ),
					),
				),
			)
		);
		self::assertSame( 'download', $run['point'] );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
		$left = glob( self::root() . '/ab-mcp-store/*/tmp/.ab-tmp-*' );
		self::assertCount( 1, $left, 'The building folder of the request that ended stays.' );
		$groups = AB_MCP_Protected_Store::groups( 'stage' );
		self::assertSame( array(), is_array( $groups ) ? $groups : array( 'unreadable' ), 'Nothing went into stage.' );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertCount( 1, glob( self::root() . '/ab-mcp-store/*/tmp/.ab-tmp-*' ), 'Young, it stays.' );
		touch( $left[0], time() - AB_MCP_Protected_Store::TMP_AGE - 10 );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertSame( array(), glob( self::root() . '/ab-mcp-store/*/tmp/.ab-tmp-*' ), 'Past TMP_AGE the clean-up takes it.' );
	}

	public function testTheInstallToolsTakeAnExactVersionOfWordpressOrgThroughTheDownloader(): void {
		// Point 6 and D2: version of wp_install_plugin and wp_install_theme, the
		// exact address through the shared downloader (https, no redirect,
		// ORG_MAX), for a unit not installed yet; a version wordpress.org does
		// not keep installs nothing; a folder there is refused by the upgrader.
		require_once AB_MCP_DIR . 'includes/tools/class-tools-install-update.php';
		AB_MCP_IT_Code::remove( 'a', 'plugin', 'ab-it-inaktiv' );
		$calls = self::wporg();
		// The update checks WordPress makes after an install, answered by the
		// stand-in too (no network in the tests).
		$hooked = ( new AB_MCP_Test_Wporg() )->hook();
		$level  = ob_get_level();
		$got   = \AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'ab-it-inaktiv', 'version' => '1.0.0' ) );
		self::assertIsArray( $got, is_wp_error( $got ) ? $got->get_error_code() . ': ' . $got->get_error_message() : '' );
		self::assertSame( '1.0.0', $got['version'] );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ), 'Exactly that version, not the newest.' );
		self::assertSame( array( 'https://downloads.wordpress.org/plugin/ab-it-inaktiv.1.0.0.zip' ), array_column( $calls->wporg->calls, 'url' ) );
		self::assertSame( 0, $calls->wporg->calls[0]['args']['redirection'] );
		self::assertSame( AB_MCP_Sync_Code_Stage::ORG_MAX + 1, $calls->wporg->calls[0]['args']['limit_response_size'] );
		$there = \AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'ab-it-inaktiv', 'version' => '1.1.0' ) );
		self::assertInstanceOf( \WP_Error::class, $there, 'A plugin installed is not replaced.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
		AB_MCP_IT_Code::remove( 'a', 'plugin', 'ab-it-inaktiv' );
		self::assertSame( 'ab_mcp_version_unavailable', \AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'ab-it-inaktiv', 'version' => '9.9.9' ) )->get_error_code() );
		self::assertSame( '', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ), 'Nothing installed.' );
		self::assertSame( 'ab_mcp_bad_version', \AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'ab-it-inaktiv', 'version' => '../1' ) )->get_error_code() );
		self::assertSame( 'ab_mcp_bad_version', \AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'ab-it-inaktiv', 'version' => array( '1.0.0' ) ) )->get_error_code(), 'A version that is no text is refused, never the newest installed.' );
		self::assertSame( '', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ), 'Nothing installed.' );
		AB_MCP_IT_Code::remove( 'a', 'theme', 'ab-it-eltern' );
		$theme = \AB_MCP_Tools_Install_Update::install_theme( array( 'slug' => 'ab-it-eltern', 'version' => '1.1.0' ) );
		self::assertIsArray( $theme, is_wp_error( $theme ) ? $theme->get_error_message() : '' );
		self::assertSame( '1.1.0', AB_MCP_IT_Code::fassung( 'a', 'theme', 'ab-it-eltern' ) );
		$hooked->unhook();
		// The skin of the upgrader leaves its output buffer open.
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
	}

	public function testAChunkThatDoesNotInflateWhenAPackageIsReadIsRefusedNeverReadAsNoLines(): void {
		// V4 of Z8b branch 4: taken() reads the lines of a package of the
		// environment chunk by chunk; a chunk that does not inflate any more
		// (here: bytes that are no gzip under their SHA-256, the mark of
		// completion written as the import writes it) is refused, never read
		// as no lines.
		$bytes  = 'no gzip at all';
		$signed = self::b(
			array(
				array(
					'op'      => 'sign_manifest',
					'package' => array(
						'bytes'       => strlen( $bytes ),
						'chunks'      => array(
							array(
								'bytes'  => strlen( $bytes ),
								'sha256' => hash( 'sha256', $bytes ),
							),
						),
						'kinds'       => array(),
						'objects'     => 1,
						'package_id'  => str_repeat( 'c', 32 ),
						'purpose'     => 'env',
						'report_hash' => null,
						'sha256'      => hash( 'sha256', $bytes ),
						'unpacked'    => 10,
					),
					'as'      => 'm',
				),
			)
		)['m'];
		$began = AB_MCP_Sync_Import::call(
			array(
				'package_id' => str_repeat( 'c', 32 ),
				'manifest'   => $signed,
				'purpose'    => 'env',
			)
		);
		self::assertIsArray( $began, is_wp_error( $began ) ? $began->get_error_message() : '' );
		$gid = null;
		foreach ( AB_MCP_Protected_Store::groups( 'in' ) as $group ) {
			if ( 'package-' . str_repeat( 'c', 32 ) === $group['label'] ) {
				$gid = $group['gid'];
			}
		}
		self::assertNotNull( $gid );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, \AB_MCP_Sync_Export::chunk_name( 0 ), $bytes ) );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, 'held-' . substr( AB_MCP_Sync_Import::held_name( hash( 'sha256', '{"format":"ab-sync-held/3","objects":[]}' ) ), 5 ), '{"format":"ab-sync-held/3","objects":[]}' ) );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Import::COMPLETE, hash( 'sha256', $bytes ) ) );
		$taken = AB_MCP_Sync_Import::taken( str_repeat( 'c', 32 ), 'env' );
		self::assertInstanceOf( \WP_Error::class, $taken );
		self::assertSame( 'ab_mcp_sync_import_unread', $taken->get_error_code() );
	}

	public function testAStageThatDoesNotReadWholeForItsCheckIsNeverReady(): void {
		// complete(): the PHP files to check only from the tree read whole as
		// its hash; a reading that ends within its time passes no file over
		// (unknown: code:syntax_unchecked, never ready).
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$env = self::environment();
		self::wporg();
		$stage = AB_MCP_Sync_Code_Stage::stage( self::RUN, $env, 'plugin', 'ab-it-inaktiv' );
		self::assertIsArray( $stage, is_wp_error( $stage ) ? $stage->get_error_code() . ': ' . $stage->get_error_message() : '' );
		// The time of a reading is over before it begins.
		AB_MCP_Sync_Code_Stage::$clock = static fn(): float => microtime( true ) - 1000.0;
		$state                         = AB_MCP_Sync_Code_Stage::complete( $stage['gid'], $stage['name'], $stage['hash'], $env );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_code() . ': ' . $state->get_error_message() : '' );
		self::assertSame( 'refused', $state['state'], var_export( $state, true ) );
		self::assertSame( 'code:syntax_unchecked', $state['refusal']['code'] );
		self::assertNull( AB_MCP_Sync_Code_Stage::ready( $stage['gid'], $stage['name'], $stage['hash'] ) );
	}

	public function testABuildingFolderGoesOnlyByItsAgeOrByItsOwnRequest(): void {
		// D4: build_dir() the one way a building folder is made; it goes by
		// delete_path() only, with what is in it: by its request, or past
		// TMP_AGE by the clean-up; a young one of another request stays whole,
		// and nothing deep in it goes on its own.
		$own = AB_MCP_Protected_Store::build_dir();
		self::assertIsString( $own );
		self::assertSame( 0, strpos( basename( $own ), AB_MCP_Protected_Store::TMP_PREFIX ) );
		mkdir( $own . '/einheit/inc', 0700, true );
		file_put_contents( $own . '/einheit/inc/a.php', '<?php' );
		self::assertTrue( AB_MCP_Protected_Store::drop_build( $own ), 'Its own request takes it with what is in it.' );
		self::assertDirectoryDoesNotExist( $own );
		$store = dirname( dirname( $own ) );
		$other = $store . '/tmp/' . AB_MCP_Protected_Store::TMP_PREFIX . 'anderer';
		mkdir( $other . '/einheit/inc', 0700, true );
		file_put_contents( $other . '/einheit/inc/a.php', '<?php' );
		self::assertFalse( AB_MCP_Protected_Store::drop_build( $other ), 'Another request\'s young folder stays.' );
		self::assertFalse( AB_MCP_Protected_Store::delete_path( $store, $other . '/einheit/inc/a.php' ), 'Nothing deep in it goes on its own.' );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertFileExists( $other . '/einheit/inc/a.php', 'The clean-up leaves a young one whole.' );
		$foreign = $store . '/tmp/fremd';
		mkdir( $foreign, 0700 );
		touch( $foreign, time() - 2 * AB_MCP_Protected_Store::TMP_AGE );
		touch( $other, time() - AB_MCP_Protected_Store::TMP_AGE - 10 );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertDirectoryDoesNotExist( $other, 'Past TMP_AGE it goes with what is in it.' );
		self::assertDirectoryExists( $foreign, 'A folder without the prefix stays.' );
		rmdir( $foreign );
		self::assertFalse( AB_MCP_Protected_Store::drop_build( $store . '/stage' ), 'Only a building folder.' );
	}
}

/**
 * The calls of the stage with the stand-in of wordpress.org for the
 * download, and the real ones else (a few switched by the case).
 */
final class CodeStageWporgCalls {

	/** @var AB_MCP_Test_Wporg */
	public $wporg;

	/** @var AB_MCP_Sync_Code_Stage_Calls */
	private $real;

	/** @var string|null */
	public $method = null;

	/** @var bool|null */
	public $tokenizer = null;

	public function __construct( ?AB_MCP_Test_Wporg $wporg = null ) {
		$this->wporg = $wporg ?? new AB_MCP_Test_Wporg();
		$this->real  = new AB_MCP_Sync_Code_Stage_Calls();
	}

	public function get( $url, array $args ) {
		return $this->wporg->get( $url, $args );
	}

	public function method() {
		return $this->method ?? $this->real->method();
	}

	public function tokenizer() {
		return $this->tokenizer ?? $this->real->tokenizer();
	}

	public function unzip( $zip, $to ) {
		return $this->real->unzip( $zip, $to );
	}

	public function unpacked_size( $zip ) {
		return $this->real->unpacked_size( $zip );
	}

	public function taken( $package_id ) {
		return $this->real->taken( $package_id );
	}

	public function extract( array $taken, array $file, $to ) {
		return $this->real->extract( $taken, $file, $to );
	}

	/** @var bool True: a file of the live unit comes out otherwise when it is copied. */
	public $corrupt = false;

	public function copy( $from, $to ) {
		$done = $this->real->copy( $from, $to );
		if ( $done && $this->corrupt ) {
			file_put_contents( $to, 'x', FILE_APPEND );
		}
		return $done;
	}

	public function mkdir( $path ) {
		return $this->real->mkdir( $path );
	}

	public function read( $path ) {
		return $this->real->read( $path );
	}

	public function parse_php( $code ) {
		$this->real->parse_php( $code );
	}

	public function memory() {
		return $this->real->memory();
	}

	/** @var bool False: no command line here. */
	public $lint = true;

	public function lint( $file, $label ) {
		return $this->lint ? $this->real->lint( $file, $label ) : array(
			'status' => 'unavailable',
			'note'   => 'no command line in this test.',
		);
	}
}
