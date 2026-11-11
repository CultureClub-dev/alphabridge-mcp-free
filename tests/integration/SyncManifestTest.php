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
				$out[ $entry['subtype'] . ':' . $entry['ref'] ] = $entry;
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

		$env = self::report();
		$katalog = AB_MCP_IT_Code::katalog();
		foreach ( array_keys( $katalog['plugins'] ) as $slug ) {
			if ( in_array( $slug, array( 'ab-it-basis', 'ab-it-allein', 'ab-it-inaktiv', 'ab-it-routinen', 'ab-it-schreiber' ), true ) ) {
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
			'the write fails (Rückgabe false)'  => array( array( 'method' => 'query', 'kind' => 'return', 'value' => false ), 'ab_mcp_staging_db_error' ),
			'the read fails (Lesefehler)'       => array( array( 'method' => 'row', 'kind' => 'return', 'value' => false ), 'ab_mcp_staging_db_error' ),
			'no row changed (Rückgabe 0)'       => array( array( 'method' => 'query', 'kind' => 'return', 'value' => 0 ), 'ab_mcp_staging_pair_changed' ),
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
					'method' => 'query',
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
		$seam( array( array( 'method' => 'query', 'kind' => 'throw', 'message' => 'gone' ) ) );
		try {
			AB_MCP_Sync_Pairs::rebase( $id, self::values( 'x', 'y' ), $now_hash, 'files', $code );
			self::fail( 'The Throwable leaves the call.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'gone', $e->getMessage() );
		}
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( $pair['base_live'], AB_MCP_Sync_Pairs::get( $id )['base_live'] );
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
