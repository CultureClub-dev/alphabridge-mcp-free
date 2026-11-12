<?php
/**
 * The one remover and the one writer of the protected store, and its
 * uninstall (Z8b branch 3; plan 3.1 Z37 and Z9, F53, F54, F33, P6 for the
 * part of branch 3), on real paths with site A:
 *
 * - F53 (Z37): a tree of an open mark, with a file daten.tmp older than
 *   TMP_AGE in it, against every way of removal of the list in plan 8.4:
 *   the clean-up of temp files, a sidecar that is foreign or cannot be
 *   read, a folder without its sidecar, remove() of the group, a group past
 *   its retention, a write that fails in the same group, the uninstall, a
 *   mark that cannot be read; the tree with daten.tmp, the mark and the
 *   instruction stay byte for byte. Without an open mark the same ways
 *   remove what they removed before. Each case watches every removal
 *   through the seam (naht_protected_store) and finds none on what the
 *   mark names;
 * - F54 (Z37): where the temp file of each write lies: in <store>/tmp/ with
 *   the prefix only, the target whole or absent, also after an end of the
 *   process at the rename (exit and the time limit, in a process of its
 *   own); another device of tmp/ and the folder writes nothing and names
 *   the folder; the first making of the store through a building folder
 *   beside it, made or gone; the control file of the self-check beside the
 *   store, gone afterwards; an undo point beside its target, whatever the
 *   store is;
 * - F33, P6 (Z9): the uninstall puts the original back where its identity
 *   is proved (the target missing, or the new version at the target),
 *   otherwise the tree, the mark and the instruction stay with the folder;
 *   Free's uninstall.php and the call Pro makes (the store's uninstall()
 *   alone) do the same; with Pro there, Free's uninstall leaves all; a
 *   rename that fails or throws, and an end of the process between the two
 *   renames of the swap back, lose nothing;
 * - Behebung 1 (Codex round 1): unreadable is never empty, missing or
 *   foreign. A sidecar that cannot be read, holds no entry of the format or
 *   lacks what the retention needs keeps its group; a foreign one goes only
 *   where the retention of each area known of it lets it go (A1); a path
 *   whose lstat() fails is gone only where its folder lists it not, and
 *   above a tree of an open mark never (A2); the uninstall asks for the lock
 *   of each folder without waiting for its holder, about ten seconds in
 *   all, and does nothing where it cannot take it (B3); the original goes
 *   back by the hash of its manifest where the mark holds one (B5).
 *
 * The cases of F53 and F33 make their tree and mark on disk in the format
 * of put_mark(), the group through open(): they run on the base of branch 3
 * as well, where they show the gap (correction tests).
 *
 * Kinds of fault per effect (plan 8.3, branch 3), where they apply here:
 * - files and folders of the store and the building folder: return false
 *   (rename, mkdir, stat), Throwable (rename), exit and time limit in a
 *   process of its own (the rename of write_file(), the renames of
 *   uninstall()), a concurrent writer (a folder at the target between the
 *   check and the rename of uninstall(): the rename does not go over it,
 *   rename() of a folder onto a folder that is not empty fails), half
 *   written (put() writes fewer bytes), a read error (a mark that cannot be
 *   read, nachgebildet and by its rights);
 * - rows (bookings, run rows): a write of the bookings that fails leaves
 *   the bookings as they were; run rows that cannot be read keep every
 *   group of a run (ProtectedStoreTest::testTheCleanUpKeepsWhatARunThatIs
 *   NotClosedHolds);
 * - foreign hooks (the uninstall of Free and Pro): both reach only
 *   AB_MCP_Protected_Store::uninstall().
 * The HTTP of the self-check (the PHP files below stage and backups) is
 * StoreTreesTest.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Process;
use AB_MCP_IT_Sites;
use AB_MCP_IT_Store_Calls;
use AB_MCP_Payload_File;
use AB_MCP_Protected_Store;
use AB_MCP_Store_Budget;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AB_MCP_Undo;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

require_once __DIR__ . '/support/class-it-store-calls.php';
// The classes of a run these cases call, as the plugin loads them where a run
// is worked on: never from an earlier case or class (each case alone).
require_once AB_MCP_DIR . 'includes/sync/class-sync-backup.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-resources.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-run.php';

final class StoreRemoverTest extends TestCase {

	/** The name of the test unit, in the plugins of site A and as the tree. */
	private const UNIT = 'ab-it-baum';

	/** The seam of the store. */
	private const SEAM = 'naht_protected_store';

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	/** @var array<int,string> Paths a case made outside the store, removed in tearDown(). */
	private $made = array();

	/** @var string[] What the watch of the seam saw removed. */
	private static $removed = array();

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		self::wipe();
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		self::$removed = array();
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
		self::assertTrue( AB_MCP_Protected_Store::available(), 'The store beside WordPress is there.' );
	}

	protected function tearDown(): void {
		if ( class_exists( AB_MCP_Test_Seam::class ) ) {
			AB_MCP_Test_Seam::restore_all();
		}
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		foreach ( array_merge( $this->made, array( self::plugins() . '/' . self::UNIT, self::plugins() . '/alphabridge-mcp-pro' ) ) as $path ) {
			self::remove_tree( $path );
		}
		$this->made = array();
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_IT_Sites::reset( 'a' );
	}

	/* ------------------------------------------------------------ helpers */

	private static function root(): string {
		return (string) AB_MCP_IT_Sites::state()['root'];
	}

	private static function plugins(): string {
		return AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/plugins';
	}

	private static function store(): string {
		$root = AB_MCP_Protected_Store::root();
		self::assertIsString( $root );
		return $root;
	}

	/** Remove what the store made beside WordPress and in the uploads of site A. */
	private static function wipe(): void {
		$paths   = array( self::root() . '/ab-mcp-store' );
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		foreach ( array_merge( (array) glob( $uploads . '/ab-mcp-store-*' ), (array) glob( $uploads . '/.ab-tmp-*' ), (array) glob( $uploads . '/ab-mcp-probe-*' ), (array) glob( self::root() . '/run/ab-store-*' ) ) as $path ) {
			$paths[] = (string) $path;
		}
		foreach ( $paths as $path ) {
			if ( 0 !== strpos( $path, self::root() . '/' ) ) {
				throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
			}
			self::remove_tree( $path );
		}
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@chmod( $path, 0600 );
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		chmod( $path, 0700 );
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry );
		}
		rmdir( $path );
	}

	/** Every file and folder below a path, relative, with the SHA-256 of each file: byte for byte. */
	private static function snapshot( string $path ): array {
		if ( is_file( $path ) ) {
			return array( '' => hash_file( 'sha256', $path ) );
		}
		$out = array();
		if ( ! is_dir( $path ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$out[ substr( (string) $file, strlen( $path ) + 1 ) ] = $file->isDir() ? 'dir' : hash_file( 'sha256', (string) $file );
		}
		ksort( $out );
		return $out;
	}

	/** The names in a folder, sorted, without . and .. */
	private static function names( string $dir ): array {
		$out = is_dir( $dir ) ? array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) : array();
		sort( $out );
		return $out;
	}

	/** A folder of a unit with its files (a plugin), made by the case. */
	private function unit( string $path, string $version ): string {
		mkdir( $path . '/data', 0755, true );
		file_put_contents( $path . '/' . self::UNIT . '.php', "<?php\n/* Plugin Name: AB IT Baum\n * Version: $version\n */\n" );
		file_put_contents( $path . '/data/export.csv', "id;wert\n1;$version\n" );
		file_put_contents( $path . '/daten.tmp', "Nutzdatei $version\n" );
		touch( $path . '/daten.tmp', time() - 3600 - 60 );
		$this->made[] = $path;
		return $path;
	}

	/**
	 * A tree in a group of backups with its mark, made as the swap of
	 * branch 5 makes them: the group through open(), the old version moved
	 * from its target into the group (rename), the new version in a group of
	 * stage where asked, and the mark and its instruction on disk in the
	 * format of put_mark() (a literal here, so the case runs on the base of
	 * branch 3, where put_mark() is not).
	 *
	 * @param int    $run    Run of the groups.
	 * @param string $state  open or closed.
	 * @param string $target what lies at the target afterwards: 'other' (a
	 *                       folder of its own), 'missing', 'new' (the new
	 *                       version, renamed there from stage).
	 * @param bool   $hash   Whether the mark holds the hash of the manifest
	 *                       of the backup (Behebung 1, B5), as put_mark()
	 *                       is given it by branch 5; else none.
	 * @return array
	 */
	private function marked( int $run, string $state = 'open', string $target = 'other', bool $hash = false ): array {
		$id     = AB_MCP_Protected_Store::install_id();
		$root   = self::store();
		$gid    = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => $run ) );
		self::assertIsString( $gid );
		$group  = $root . '/backups/' . AB_MCP_Payload_File::name( 'group|backups|' . $gid, $id, '' );
		$at     = self::plugins() . '/' . self::UNIT;
		self::remove_tree( $at );
		$this->unit( $at, '1.0.0' );
		$orig   = stat( $at );
		$tree   = $group . '/' . self::UNIT;
		self::assertTrue( rename( $at, $tree ), 'The old version goes into the backup by a rename.' );
		$trees  = array(
			array(
				'role' => 'backup',
				'path' => substr( $tree, strlen( $root ) + 1 ),
				'form' => 'folder',
				'dev'  => (int) $orig['dev'],
				'ino'  => (int) $orig['ino'],
				'hash' => $hash ? self::hash_of( $tree ) : null,
			),
		);
		$stage  = null;
		$sgroup = null;
		if ( 'new' === $target ) {
			// The new version in its own folder of the store (in a group of
			// backups: the area stage is new with branch 3), then renamed
			// to the target.
			$sgid   = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => $run ) );
			$sgroup = $root . '/backups/' . AB_MCP_Payload_File::name( 'group|backups|' . $sgid, $id, '' );
			$stage  = $sgroup . '/' . self::UNIT;
			$this->unit( $stage, '2.0.0' );
			$new     = stat( $stage );
			$trees[] = array(
				'role' => 'stage',
				'path' => substr( $stage, strlen( $root ) + 1 ),
				'form' => 'folder',
				'dev'  => (int) $new['dev'],
				'ino'  => (int) $new['ino'],
				'hash' => null,
			);
			self::assertTrue( rename( $stage, $at ) );
		} elseif ( 'other' === $target ) {
			$this->unit( $at, '9.9.9' );
		}
		$mid = bin2hex( random_bytes( 16 ) );
		@mkdir( $root . '/marks', 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		mkdir( $root . '/marks/' . $mid, 0700 );
		$mark = array(
			'format' => 'ab-mcp-mark/1',
			'mid'    => $mid,
			'state'  => $state,
			'begun'  => time() - 3 * 86400,
			'closed' => 'closed' === $state ? time() - 2 * 86400 : 0,
			'store'  => $root,
			'where'  => 'outside',
			'run_id' => $run,
			'seq'    => 1,
			'op'     => 'swap',
			'unit'   => array(
				'kind' => 'plugin',
				'ref'  => self::UNIT,
			),
			'target' => $at,
			'trees'  => $trees,
			'active' => array(),
			'note'   => '',
		);
		file_put_contents( $root . '/marks/' . $mid . '/mark.json', json_encode( $mark, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		file_put_contents( $root . '/marks/' . $mid . '/WIEDERHERSTELLEN.txt', "[zwischen-den-umbenennungen]\n\$ true\n" );
		return array(
			'id'     => $id,
			'root'   => $root,
			'gid'    => $gid,
			'group'  => $group,
			'side'   => $group . '.json',
			'tree'   => $tree,
			'stage'  => $stage,
			'target' => $at,
			'mid'    => $mid,
			'marks'  => $root . '/marks/' . $mid,
			'kept'   => array(
				'tree'  => self::snapshot( $tree ),
				'marks' => self::snapshot( $root . '/marks/' . $mid ),
			),
		);
	}

	/** The hash of the manifest of a folder now, as the store reads a tree (read_tree()). */
	private static function hash_of( string $path ): string {
		$read = \AB_MCP_Sync_File_Manifest::unit( dirname( $path ), basename( $path ), 'live', microtime( true ) + 45, true, 'tree' );
		self::assertTrue( $read['complete'] );
		return (string) $read['hash'];
	}

	/** The tree with daten.tmp, the mark and the instruction byte for byte where they were. */
	private static function assert_kept( array $m, string $why ): void {
		self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ), "$why: the tree with daten.tmp stays byte for byte." );
		self::assertArrayHasKey( 'daten.tmp', self::snapshot( $m['tree'] ), $why );
		self::assertSame( $m['kept']['marks'], self::snapshot( $m['marks'] ), "$why: the mark and its instruction stay." );
		foreach ( self::$removed as $path ) {
			foreach ( array( $m['tree'], $m['marks'] ) as $named ) {
				self::assertFalse( $path === $named || 0 === strpos( $path, $named . '/' ), "$why: nothing the mark names is removed, $path was." );
			}
		}
	}

	/**
	 * Watch every removal of the store through its seam (unlink, rmdir),
	 * where the seam is there (it is from branch 3 on).
	 *
	 * @param array[]       $faults Further faults of the plan.
	 * @param object|string $real   The real calls (AB_MCP_IT_Store_Calls for
	 *                              a read error of one path).
	 */
	private static function watch( array $faults = array(), $real = 'AB_MCP_Protected_Store_Calls' ): void {
		if ( ! property_exists( AB_MCP_Protected_Store::class, self::SEAM ) ) {
			return;
		}
		$seen = static function ( string $method, array $args ): void {
			self::$removed[] = (string) $args[0];
		};
		AB_MCP_Test_Seam::install(
			AB_MCP_Protected_Store::class,
			self::SEAM,
			$real,
			array_merge(
				$faults,
				array(
					array(
						'method' => 'unlink',
						'kind'   => 'before',
						'before' => $seen,
					),
					array(
						'method' => 'rmdir',
						'kind'   => 'before',
						'before' => $seen,
					),
				)
			)
		);
	}

	/** A run row of site A. */
	private static function run_row( int $run, string $state, int $keep_until = 0, int $qualified = 0 ): void {
		global $wpdb;
		$wpdb->insert(
			AB_MCP_Sync_Schema::table( 'runs' ),
			array(
				'run_id'            => $run,
				'type'              => 'push',
				'state'             => $state,
				'keep_until'        => $keep_until,
				'forward_closed_at' => $qualified,
			)
		);
		AB_MCP_Sync_Schema::forget();
	}

	/** Free's uninstall as WordPress runs it (uninstall_plugin()), in a process of its own. */
	private static function uninstall_free(): void {
		$command = array( PHP_BINARY, __DIR__ . '/bin/uninstall-site.php', AB_MCP_IT_Sites::state_file(), 'a' );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$out     = stream_get_contents( $pipes[1] );
		$err     = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $out . $err );
		self::assertSame( array( 'uninstalled' => true ), json_decode( (string) $out, true ), $out . $err );
	}

	/** The store's uninstall() alone, as Pro calls it (ab_mcp_pro_uninstall_takeover()), in a process of site A. */
	private static function uninstall_as_pro( array $faults = array() ): array {
		$plan = array( 'calls' => array( array( 'call' => 'AB_MCP_Protected_Store::uninstall' ) ) );
		if ( array() !== $faults ) {
			$plan['seams'] = array(
				array(
					'owner'    => AB_MCP_Protected_Store::class,
					'property' => self::SEAM,
					'real'     => 'AB_MCP_Protected_Store_Calls',
					'faults'   => $faults,
				),
			);
		}
		return AB_MCP_IT_Process::calls( 'a', $plan );
	}

	/* ------------------------------------------------- F53: Z37 per way */

	public function testTheCleanUpOfTempFilesTakesOnlyOwnOnesAndNeverATreeOfAnOpenMark(): void {
		$m = $this->marked( 3 );
		@mkdir( $m['root'] . '/tmp', 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$old = time() - 3600 - 60;
		foreach ( array( $m['root'] . '/tmp/.ab-tmp-alt', $m['root'] . '/tmp/daten.tmp', $m['root'] . '/x.0a1b2c3d.tmp', $m['group'] . '/post-2.0a1b2c3d.tmp' ) as $file ) {
			file_put_contents( $file, 'x' );
			touch( $file, $old );
		}
		self::watch();
		AB_MCP_Protected_Store::cleanup( time() );
		self::assert_kept( $m, 'clean-up of temp files' );
		self::assertFileDoesNotExist( $m['root'] . '/tmp/.ab-tmp-alt', 'An own temp file, old, goes.' );
		self::assertFileExists( $m['root'] . '/tmp/daten.tmp', 'A file ending in .tmp without the prefix stays.' );
		self::assertFileExists( $m['root'] . '/x.0a1b2c3d.tmp', 'A file ending in .tmp outside tmp/ stays.' );
		self::assertFileExists( $m['group'] . '/post-2.0a1b2c3d.tmp', 'Also in a group.' );
	}

	public function testAForeignOrUnreadableSidecarDoesNotTakeATreeOfAnOpenMark(): void {
		$m = $this->marked( 3 );
		// Read whole, another installation's and past its retention: the
		// way of a foreign sidecar to the removal of its group (one that
		// cannot be read keeps its group before that, Behebung 1, A1).
		self::sidecar_as(
			$m['side'],
			array(
				'gid'     => str_repeat( 'f', 32 ),
				'created' => time() - 30 * 86400,
			)
		);
		self::watch();
		AB_MCP_Protected_Store::cleanup( time() );
		self::assert_kept( $m, 'foreign sidecar' );
		self::assertFileExists( $m['side'], 'The group is not gone whole, so its sidecar stays.' );
	}

	public function testAFolderWithoutItsSidecarKeepsATreeOfAnOpenMark(): void {
		$m = $this->marked( 3 );
		unlink( $m['side'] );
		touch( $m['group'], time() - 2 * 86400 );
		self::watch();
		AB_MCP_Protected_Store::cleanup( time() );
		self::assert_kept( $m, 'folder without its sidecar' );
	}

	public function testRemoveOfTheGroupLeavesATreeOfAnOpenMarkAndTakesTheRest(): void {
		$m = $this->marked( 3 );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'backups', $m['gid'], 'post-1', 'a row' ) );
		self::watch();
		self::assertFalse( AB_MCP_Protected_Store::remove( 'backups', $m['gid'] ), 'Not removed whole.' );
		self::assert_kept( $m, 'remove()' );
		self::assertFileDoesNotExist( $m['group'] . '/post-1', 'What no mark names goes.' );
		self::assertFileExists( $m['side'], 'The sidecar stays with the folder.' );
	}

	public function testAGroupPastItsRetentionKeepsATreeOfAnOpenMark(): void {
		self::run_row( 3, 'closed_done', time() - 10 );
		$m = $this->marked( 3 );
		$other = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 3 ) );
		self::assertIsString( $other );
		self::watch();
		$done = AB_MCP_Protected_Store::cleanup( time() );
		self::assert_kept( $m, 'retention over' );
		self::assertSame( array( $m['gid'] ), array_column( (array) AB_MCP_Protected_Store::groups( 'backups' ), 'gid' ), 'The other group of the run goes, the one with the tree stays.' );
		self::assertSame( 1, $done['removed'] );
	}

	public function testAWriteThatFailsInTheGroupOfATreeOfAnOpenMarkLeavesTheTree(): void {
		$m = $this->marked( 3 );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'backups', $m['gid'], 'post-0', 'a row before' ) );
		// The file goes in, its sidecar cannot be written: the file goes again.
		self::watch(
			array(
				array(
					'method' => 'rename',
					'kind'   => 'return',
					'value'  => false,
					'nth'    => 2,
				),
			)
		);
		$res = AB_MCP_Protected_Store::put( 'backups', $m['gid'], 'post-1', 'a row' );
		self::assertSame( 'ab_mcp_store_write', $res->get_error_code() );
		self::assertFileDoesNotExist( $m['group'] . '/post-1' );
		self::assertFileExists( $m['group'] . '/post-0', 'Only what it wrote goes.' );
		self::assert_kept( $m, 'put() whose sidecar fails' );
		AB_MCP_Test_Seam::restore_all();
		// The file itself cannot be written; then it throws.
		foreach ( array( array( 'kind' => 'return', 'value' => false ), array( 'kind' => 'throw' ) ) as $fault ) {
			self::watch( array( array( 'method' => 'rename' ) + $fault ) );
			$res = AB_MCP_Protected_Store::put( 'backups', $m['gid'], 'post-2', 'a row' );
			self::assertSame( 'ab_mcp_store_write', $res->get_error_code(), $fault['kind'] );
			AB_MCP_Test_Seam::restore_all();
			self::assert_kept( $m, 'put() that fails: ' . $fault['kind'] );
			self::assertSame( array(), self::names( $m['root'] . '/tmp' ), 'Its temp file went: ' . $fault['kind'] );
		}
		// Half written: fewer bytes than given.
		self::watch(
			array(
				array(
					'method' => 'put',
					'kind'   => 'half',
					'arg'    => 1,
					'keep'   => 3,
					'value'  => 3,
				),
			)
		);
		self::assertSame( 'ab_mcp_store_write', AB_MCP_Protected_Store::bind( 'backups', $m['gid'], 7 )->get_error_code(), 'write_entry() of a sidecar written in part.' );
		AB_MCP_Test_Seam::restore_all();
		self::assert_kept( $m, 'write_entry() half' );
		self::assertSame( 3, json_decode( (string) file_get_contents( $m['side'] ), true )['run_id'], 'The sidecar as it was.' );
		self::assertSame( array(), self::names( $m['root'] . '/tmp' ) );
	}

	public function testAGroupThatCannotBeMadeLeavesNoSidecarAndTouchesNoTree(): void {
		$m    = $this->marked( 3 );
		$area = $m['root'] . '/backups';
		$was  = self::names( $area );
		foreach ( array( array( 'kind' => 'return', 'value' => false ), array( 'kind' => 'throw' ) ) as $fault ) {
			self::watch( array( array( 'method' => 'mkdir', 'nth' => 1 ) + $fault ) );
			try {
				$res = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 3 ) );
				self::assertSame( 'ab_mcp_store_write', $res->get_error_code(), $fault['kind'] );
			} catch ( RuntimeException $e ) {
				self::assertSame( 'throw', $fault['kind'], $e->getMessage() );
			}
			AB_MCP_Test_Seam::restore_all();
			if ( 'return' === $fault['kind'] ) {
				self::assertSame( $was, self::names( $area ), 'The sidecar written first went again.' );
			}
			self::assert_kept( $m, 'open() whose folder fails: ' . $fault['kind'] );
		}
	}

	public function testTheRemoverTakesNothingOutsideTheStoreNorAForeignTempFile(): void {
		$root    = self::store();
		$outside = self::root() . '/run/ab-store-outside.txt';
		file_put_contents( $outside, 'not the store\'s' );
		self::assertFalse( AB_MCP_Protected_Store::delete_path( $root, $outside ), 'Outside the store only what this request made and noted.' );
		self::assertFileExists( $outside );
		self::assertFalse( AB_MCP_Protected_Store::delete_path( $root, $root . '/tmp/../owner.json' ) );
		self::assertFileExists( $root . '/owner.json' );
		$tmp = $root . '/tmp';
		foreach ( array( 'daten.tmp', '.ab-tmp-jung' ) as $name ) {
			file_put_contents( $tmp . '/' . $name, 'x' );
			self::assertFalse( AB_MCP_Protected_Store::delete_path( $root, $tmp . '/' . $name ), $name );
			self::assertFileExists( $tmp . '/' . $name );
		}
		touch( $tmp . '/daten.tmp', time() - AB_MCP_Protected_Store::TMP_AGE - 10 );
		self::assertFalse( AB_MCP_Protected_Store::delete_path( $root, $tmp . '/daten.tmp' ), 'Old, but not with the prefix: not by its ending.' );
		touch( $tmp . '/.ab-tmp-jung', time() - AB_MCP_Protected_Store::TMP_AGE - 10 );
		self::assertTrue( AB_MCP_Protected_Store::delete_path( $root, $tmp . '/.ab-tmp-jung' ), 'Own and old: it goes.' );
		$made = AB_MCP_Protected_Store::temp_file( $root );
		self::assertIsString( $made );
		self::assertTrue( AB_MCP_Protected_Store::delete_path( $root, $made ), 'Made and noted by this request: it goes at once.' );
		unlink( $outside );
	}

	public function testTheMeasurementOfTheResourcesLeavesNoFileInTheStore(): void {
		$root   = self::store();
		$before = self::names( $root );
		$m      = \AB_MCP_Sync_Resources::measure();
		self::assertGreaterThan( 0.0, $m['write_seconds'] );
		self::assertSame( $before, self::names( $root ), 'Nothing beside the files of the store.' );
		self::assertSame( array(), self::names( $root . '/tmp' ), 'Its temp file went.' );
	}

	public function testTheUninstallMovesNoBackupWhoseIdentityIsNotTheMarks(): void {
		$m = $this->marked( 3, 'open', 'missing' );
		// The backup replaced by a copy of it: the same bytes, another inode.
		rename( $m['tree'], $m['tree'] . '-alt' );
		mkdir( $m['tree'] . '/data', 0755, true );
		foreach ( array( self::UNIT . '.php', 'data/export.csv', 'daten.tmp' ) as $file ) {
			copy( $m['tree'] . '-alt/' . $file, $m['tree'] . '/' . $file );
		}
		touch( $m['tree'] . '/daten.tmp', time() - 3600 - 60 );
		self::remove_tree( $m['tree'] . '-alt' );
		self::uninstall_as_pro();
		self::assertFileDoesNotExist( $m['target'], 'Not proved: not put back.' );
		self::assert_kept( $m, 'backup of another identity' );
	}

	public function testAnEmptyFolderAtTheTargetIsThereSoTheUninstallMovesNothing(): void {
		$m = $this->marked( 3, 'open', 'missing' );
		mkdir( $m['target'], 0755 );
		self::uninstall_as_pro();
		self::assertSame( array(), self::names( $m['target'] ), 'The folder at the target is left as it is.' );
		self::assert_kept( $m, 'an empty folder at the target' );
	}

	public function testTheUninstallKeepsATreeOfAnOpenMarkWhoseOriginalIsNotProvedAndTakesTheRest(): void {
		$m       = $this->marked( 3 );
		$reports = AB_MCP_Protected_Store::open( 'reports' );
		self::assertIsString( $reports );
		$other   = self::snapshot( $m['target'] );
		self::uninstall_free();
		self::assert_kept( $m, 'uninstall' );
		self::assertSame( $other, self::snapshot( $m['target'] ), 'The folder at the target is not touched.' );
		self::assertSame( array( '.htaccess', 'backups', 'index.php', 'marks', 'owner.json', 'store.lock', 'web.config' ), self::names( $m['root'] ), 'The folder stays this installation\'s and closed to the web; what no mark names went (the report among it).' );
		self::assertSame( array( basename( $m['group'] ), basename( $m['side'] ) ), self::names( $m['root'] . '/backups' ), 'The group of the tree stays with its sidecar.' );
		self::assertSame( array( self::UNIT ), self::names( $m['group'] ) );
	}

	public function testWhileAMarkCannotBeReadNothingInStageBackupsAndMarksGoes(): void {
		$m       = $this->marked( 3 );
		$reports = AB_MCP_Protected_Store::open( 'reports' );
		$plain   = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 3 ) );
		self::run_row( 3, 'closed_done', time() - 10 );
		$id               = $m['id'];
		$side_rep         = $m['root'] . '/reports/' . AB_MCP_Payload_File::name( 'group|reports|' . $reports, $id, '' ) . '.json';
		$entry            = json_decode( (string) file_get_contents( $side_rep ), true );
		$entry['created'] = time() - 2 * 86400;
		file_put_contents( $side_rep, json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
		$plain_dir = $m['root'] . '/backups/' . AB_MCP_Payload_File::name( 'group|backups|' . $plain, $id, '' );
		// Nachgebildet: the read of the mark fails (the read of the store's
		// seam of that file alone: the sidecars are read through it too).
		$calls       = new AB_MCP_IT_Store_Calls();
		$calls->fail = array( 'read' => array( $m['marks'] . '/mark.json' ) );
		self::watch( array(), $calls );
		$done = AB_MCP_Protected_Store::cleanup( time() );
		self::assert_kept( $m, 'mark unreadable: clean-up' );
		// Behebung 4, B1: the groups past their retention that the mark kept
		// (the group of the tree and the plain one) count as unknown.
		self::assertSame( 2, $done['unknown'], 'Kept by a mark that cannot be read: counted unknown.' );
		self::assertDirectoryExists( $plain_dir, 'A group of backups past its retention stays while a mark cannot be read.' );
		self::assertSame( array(), self::names( $m['root'] . '/reports' ), 'A report past its time goes all the same.' );
		self::assertFalse( AB_MCP_Protected_Store::remove( 'backups', $plain ) );
		self::assertDirectoryExists( $plain_dir );
		AB_MCP_Test_Seam::restore_all();
		self::assertTrue( AB_MCP_Protected_Store::remove( 'backups', $plain ), 'Once the mark reads, the group without a tree goes.' );
	}

	public function testAMarkWhoseFileCannotBeReadKeepsStageAndBackups(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'The rights of a file stop no process of root.' );
		}
		$m = $this->marked( 3 );
		self::run_row( 3, 'closed_done', time() - 10 );
		chmod( $m['marks'] . '/mark.json', 0000 );
		try {
			AB_MCP_Protected_Store::cleanup( time() );
			self::assertFalse( AB_MCP_Protected_Store::remove( 'backups', $m['gid'] ) );
		} finally {
			chmod( $m['marks'] . '/mark.json', 0600 );
		}
		self::assert_kept( $m, 'mark without read rights' );
		// The folder of the marks itself cannot be listed.
		chmod( $m['root'] . '/marks', 0000 );
		try {
			AB_MCP_Protected_Store::cleanup( time() );
			self::assertFalse( AB_MCP_Protected_Store::remove( 'backups', $m['gid'] ) );
		} finally {
			chmod( $m['root'] . '/marks', 0700 );
		}
		self::assert_kept( $m, 'folder of the marks without read rights' );
	}

	public function testAMarkWhoseFolderIsListedButCannotBeReadKeepsStageAndBackups(): void {
		// read_mark(): a mark is absent only where the folder of the marks
		// lists none of its name; one that is listed and whose own folder
		// cannot be listed cannot be read, and nothing it may name goes
		// (Behebung 3, the Gegenprobe of the list of substitute values).
		$m = $this->marked( 3 );
		self::run_row( 3, 'closed_done', time() - 10 );
		$calls = self::failing( array( 'scandir' => array( $m['marks'] ) ) );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertFalse( AB_MCP_Protected_Store::remove( 'backups', $m['gid'] ) );
		AB_MCP_Test_Seam::restore_all();
		self::assertContains( array( 'scandir', $m['marks'] ), $calls->failed );
		self::assert_kept( $m, 'folder of a mark that cannot be listed' );
	}

	public function testWithoutAnOpenMarkTheWaysRemoveWhatTheyRemovedBefore(): void {
		// A closed mark names nothing that stays.
		$m = $this->marked( 3, 'closed' );
		self::watch();
		self::assertTrue( AB_MCP_Protected_Store::remove( 'backups', $m['gid'] ) );
		self::assertFileDoesNotExist( $m['tree'] );
		self::assertFileDoesNotExist( $m['side'] );
		// A group past its retention, with its tree, goes in the clean-up.
		self::run_row( 4, 'closed_done', time() - 10 );
		$gid  = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 4 ) );
		$dir  = $m['root'] . '/backups/' . AB_MCP_Payload_File::name( 'group|backups|' . $gid, $m['id'], '' );
		$this->unit( $dir . '/' . self::UNIT, '1.0.0' );
		$done = AB_MCP_Protected_Store::cleanup( time() );
		self::assertSame( 1, $done['removed'] );
		self::assertFileDoesNotExist( $dir );
		if ( class_exists( AB_MCP_Test_Seam::class ) ) {
			AB_MCP_Test_Seam::restore_all();
		}
		// The uninstall takes the whole folder.
		$this->marked( 5, 'closed' );
		self::uninstall_free();
		self::assertFileDoesNotExist( $m['root'] );
		self::assertFileDoesNotExist( self::root() . '/ab-mcp-store', 'With the last folder its parent.' );
	}

	public function testAClosedMarkGoesADayAfterItClosedAndAFolderWithoutItsMarkAfterADay(): void {
		$m    = $this->marked( 3, 'closed' );
		$open = $this->marked( 4 );
		$half = $m['root'] . '/marks/' . str_repeat( 'b', 32 );
		mkdir( $half, 0700 );
		touch( $half, time() - 2 * 86400 );
		$young = $m['root'] . '/marks/' . str_repeat( 'c', 32 );
		mkdir( $young, 0700 );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertFileDoesNotExist( $m['marks'], 'A mark closed two days ago went with the clean-up.' );
		self::assertFileDoesNotExist( $half, 'A folder of a mark that holds none, after a day.' );
		self::assertDirectoryExists( $young, 'One of today stays: its mark may be written right now.' );
		self::assert_kept( $open, 'an open mark' );
		self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ), 'Its tree is a matter of its group (kept()), not of the mark.' );
	}

	/* ---------------------------------------- F33, P6: Z9, the uninstall */

	public function testTheUninstallPutsTheOriginalBackWhereTheTargetIsMissing(): void {
		$m    = $this->marked( 3, 'open', 'missing' );
		$orig = $m['kept']['tree'];
		$ino  = fileinode( $m['tree'] );
		self::uninstall_free();
		self::assertSame( $orig, self::snapshot( $m['target'] ), 'The original lies at the target again, byte for byte.' );
		self::assertSame( $ino, fileinode( $m['target'] ), 'The same folder, moved back.' );
		self::assertFileDoesNotExist( $m['root'], 'Nothing is left to keep: the folder of the store goes.' );
	}

	public function testTheUninstallSwapsBackWhereTheTargetHoldsTheNewVersion(): void {
		$m    = $this->marked( 3, 'open', 'new' );
		$orig = $m['kept']['tree'];
		self::assertStringContainsString( '2.0.0', (string) file_get_contents( $m['target'] . '/' . self::UNIT . '.php' ) );
		self::uninstall_free();
		self::assertSame( $orig, self::snapshot( $m['target'] ), 'The old version is back at the target.' );
		self::assertFileDoesNotExist( $m['root'], 'The new version went with the folder of the store.' );
	}

	public function testTheCallProMakesPutsTheOriginalBackAndWithProThereFreesUninstallLeavesAll(): void {
		$m = $this->marked( 3, 'open', 'missing' );
		// Pro is there: Free's uninstall.php leaves the shared data, the
		// store included.
		mkdir( self::plugins() . '/alphabridge-mcp-pro', 0755 );
		file_put_contents( self::plugins() . '/alphabridge-mcp-pro/alphabridge-mcp-pro.php', "<?php\n/* Plugin Name: Pro (test) */\n" );
		self::uninstall_free();
		self::assert_kept( $m, 'Free uninstalled while Pro is there' );
		self::assertFileDoesNotExist( $m['target'] );
		self::remove_tree( self::plugins() . '/alphabridge-mcp-pro' );
		// Pro's uninstall without Free calls the store's uninstall() alone.
		self::uninstall_as_pro();
		self::assertSame( $m['kept']['tree'], self::snapshot( $m['target'] ) );
		self::assertFileDoesNotExist( $m['root'] );
	}

	public function testARenameOfTheUninstallThatFailsOrThrowsLosesNothing(): void {
		foreach ( array( array( 'kind' => 'return', 'value' => false ), array( 'kind' => 'throw' ) ) as $fault ) {
			$m = $this->marked( 3, 'open', 'missing' );
			self::uninstall_as_pro( array( array( 'method' => 'rename' ) + $fault ) );
			self::assert_kept( $m, 'rename ' . $fault['kind'] );
			self::assertFileDoesNotExist( $m['target'], 'Nothing was moved.' );
			self::assertFileExists( $m['root'] . '/owner.json', 'The folder stays this installation\'s.' );
			self::wipe();
			self::remove_tree( $m['target'] );
			AB_MCP_Protected_Store::forget();
			AB_MCP_IT_Sites::reset( 'a' );
			self::assertTrue( AB_MCP_Protected_Store::available() );
		}
	}

	public function testAnEndOfTheProcessBetweenTheRenamesOfTheSwapBackLosesNothingAndTheNextUninstallEndsIt(): void {
		foreach ( array( 'no_shutdown', 'timeout' ) as $mode ) {
			$m = $this->marked( 3, 'open', 'new' );
			AB_MCP_IT_Process::abort(
				'a',
				array(
					'seams' => array(
						array(
							'owner'    => AB_MCP_Protected_Store::class,
							'property' => self::SEAM,
							'real'     => 'AB_MCP_Protected_Store_Calls',
							'faults'   => array(
								array(
									'method' => 'rename',
									'nth'    => 2,
									'kind'   => 'abort',
									'point'  => 'zwischen-den-umbenennungen',
									'mode'   => $mode,
								),
							),
						),
					),
					'calls' => array( array( 'call' => 'AB_MCP_Protected_Store::uninstall' ) ),
				)
			);
			// The new version went back to its place, the old one not yet.
			self::assertFileDoesNotExist( $m['target'], $mode );
			self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ), $mode . ': the original whole in its backup.' );
			self::assertFileExists( $m['marks'] . '/mark.json', $mode );
			self::assertDirectoryExists( $m['stage'], $mode . ': the new version in its place.' );
			// The uninstall once more: the target is missing, the original goes back.
			self::uninstall_as_pro();
			self::assertSame( $m['kept']['tree'], self::snapshot( $m['target'] ), $mode );
			self::assertFileDoesNotExist( $m['root'], $mode );
			self::remove_tree( $m['target'] );
			AB_MCP_Protected_Store::forget();
			AB_MCP_IT_Sites::reset( 'a' );
			self::assertTrue( AB_MCP_Protected_Store::available() );
		}
	}

	public function testAFolderThatAppearsAtTheTargetIsNotWrittenOver(): void {
		$m = $this->marked( 3, 'open', 'missing' );
		// A concurrent writer puts a folder at the target between the check
		// and the rename.
		self::uninstall_as_pro(
			array(
				array(
					'method' => 'rename',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => array(
						array(
							'op'   => 'mkdir',
							'path' => $m['target'] . '/von-anderswo',
						),
						array(
							'op'    => 'put_file',
							'path'  => $m['target'] . '/von-anderswo/datei.txt',
							'bytes' => base64_encode( 'fremd' ),
						),
					),
				),
			)
		);
		self::assert_kept( $m, 'a folder at the target meanwhile' );
		self::assertSame( array( 'von-anderswo' ), self::names( $m['target'] ), 'The folder that appeared is not written over.' );
	}

	/* ------------------------------------- F54: where a temp file lies */

	public function testAWriteOfTheStoreThatIsCutOffLeavesItsTempFileInTmpOnly(): void {
		$root = self::store();
		$gid  = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 100 ) );
		$dir  = $root . '/in/' . AB_MCP_Payload_File::name( 'group|in|' . $gid, AB_MCP_Protected_Store::install_id(), '' );
		$side = (string) file_get_contents( $dir . '.json' );
		foreach ( array( 'no_shutdown', 'timeout' ) as $mode ) {
			AB_MCP_IT_Process::abort(
				'a',
				array(
					'seams' => array(
						array(
							'owner'    => AB_MCP_Protected_Store::class,
							'property' => self::SEAM,
							'real'     => 'AB_MCP_Protected_Store_Calls',
							'faults'   => array(
								array(
									'method' => 'rename',
									'kind'   => 'abort',
									'point'  => 'vor-dem-umbenennen',
									'mode'   => $mode,
								),
							),
						),
					),
					'calls' => array(
						array(
							'call' => 'AB_MCP_Protected_Store::put',
							'args' => array( 'in', $gid, 'chunk-' . $mode, 'payload' ),
						),
					),
				)
			);
			self::assertSame( array(), self::names( $dir ), $mode . ': the target is not there, and no temp file lies in the group.' );
			self::assertSame( $side, (string) file_get_contents( $dir . '.json' ), $mode . ': the sidecar as it was.' );
		}
		$left = self::names( $root . '/tmp' );
		self::assertCount( 2, $left, 'The temp file of each cut-off write lies in tmp/.' );
		foreach ( $left as $file ) {
			self::assertStringStartsWith( '.ab-tmp-', $file );
			touch( $root . '/tmp/' . $file, time() - AB_MCP_Protected_Store::TMP_AGE - 10 );
		}
		AB_MCP_Protected_Store::cleanup();
		self::assertSame( array(), self::names( $root . '/tmp' ), 'The clean-up takes them after TMP_AGE.' );
		// Written whole, then: the file and nothing else.
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, 'chunk-ok', 'payload' ) );
		self::assertSame( array( 'chunk-ok' ), self::names( $dir ) );
		self::assertSame( array(), self::names( $root . '/tmp' ) );
	}

	public function testAnotherDeviceOfTmpAndTheFolderWritesNothingAndNamesTheFolder(): void {
		$root = self::store();
		$id   = AB_MCP_Protected_Store::install_id();
		$gid  = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 2 ) );
		$name = AB_MCP_Payload_File::name( 'group|backups|' . $gid, $id, '' );
		$side = (string) file_get_contents( $root . '/backups/' . $name . '.json' );
		$other = array(
			'dev'  => -1,
			'ino'  => 1,
			'mode' => 040700,
		);
		// The second stat() of a write is the folder of the file.
		foreach ( array( 'put', 'bind', 'book' ) as $what ) {
			AB_MCP_Test_Seam::install(
				AB_MCP_Protected_Store::class,
				self::SEAM,
				'AB_MCP_Protected_Store_Calls',
				array(
					array(
						'method' => 'stat',
						'nth'    => 2,
						'kind'   => 'return',
						'value'  => $other,
					),
				)
			);
			if ( 'put' === $what ) {
				$res = AB_MCP_Protected_Store::put( 'backups', $gid, 'post-1', 'a row' );
				self::assertSame( 'ab_mcp_store_device', $res->get_error_code() );
				self::assertStringContainsString( 'backups/' . $name, $res->get_error_message(), 'The answer names the folder.' );
				self::assertSame( 'backups/' . $name, $res->get_error_data()['folder'] );
				self::assertSame( array(), self::names( $root . '/backups/' . $name ), 'Nothing written.' );
			} elseif ( 'bind' === $what ) {
				self::assertSame( 'ab_mcp_store_write', AB_MCP_Protected_Store::bind( 'backups', $gid, 9 )->get_error_code() );
				self::assertSame( $side, (string) file_get_contents( $root . '/backups/' . $name . '.json' ), 'The sidecar as it was.' );
			} else {
				self::run_row( 8, 'open' );
				self::assertSame( 'ab_mcp_store_test_write', AB_MCP_Store_Budget::book( 8, 10 )->get_error_code(), 'The test write itself is a write of the store.' );
				self::assertFileDoesNotExist( $root . '/bookings.json' );
			}
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( array(), self::names( $root . '/tmp' ), $what . ': no temp file left.' );
		}
	}

	public function testTheFirstMakingOfTheStoreGoesThroughABuildingFolderBesideIt(): void {
		self::wipe();
		AB_MCP_Protected_Store::forget();
		$parent = self::root() . '/ab-mcp-store';
		$seen   = array();
		// While it is made: the building folder beside the future store,
		// with the prefix, and in it tmp/ before anything else.
		AB_MCP_Test_Seam::install(
			AB_MCP_Protected_Store::class,
			self::SEAM,
			'AB_MCP_Protected_Store_Calls',
			array(
				array(
					'method' => 'rename',
					'kind'   => 'before',
					'before' => static function ( string $method, array $args ) use ( &$seen ): void {
						$seen[] = array( (string) $args[0], (string) $args[1], is_dir( (string) $args[0] ) ? self::names( (string) $args[0] ) : null );
					},
				),
			)
		);
		self::assertTrue( AB_MCP_Protected_Store::proof( null, true )['available'] );
		AB_MCP_Test_Seam::restore_all();
		$last = end( $seen );
		self::assertStringStartsWith( $parent . '/.ab-tmp-', $last[0], 'The last rename is the building folder, beside the store, with the prefix.' );
		self::assertSame( $parent . '/' . AB_MCP_Protected_Store::install_id(), $last[1] );
		self::assertContains( 'tmp', (array) $last[2] );
		self::assertContains( 'owner.json', (array) $last[2] );
		foreach ( array_slice( $seen, 0, -1 ) as $rename ) {
			self::assertStringStartsWith( $last[0] . '/tmp/.ab-tmp-', $rename[0], 'Every file of it came from its own tmp/.' );
		}
		self::assertSame( array( AB_MCP_Protected_Store::install_id() ), self::names( $parent ), 'After it: the store, no building folder.' );
		self::assertSame( array(), self::names( self::store() . '/tmp' ) );

		// The rename into place fails: the building folder goes, no half store.
		self::wipe();
		AB_MCP_Protected_Store::forget();
		foreach ( array( array( 'kind' => 'return', 'value' => false ), array( 'kind' => 'throw' ) ) as $fault ) {
			AB_MCP_Test_Seam::install(
				AB_MCP_Protected_Store::class,
				self::SEAM,
				'AB_MCP_Protected_Store_Calls',
				array( array( 'method' => 'rename', 'nth' => 5 ) + $fault )
			);
			try {
				AB_MCP_Protected_Store::proof( null, true );
			} catch ( \Throwable $e ) {
				self::assertSame( 'throw', $fault['kind'], $e->getMessage() );
			}
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( array(), self::names( $parent ), $fault['kind'] . ': no building folder and no half store beside WordPress.' );
			AB_MCP_Protected_Store::forget();
		}
	}

	public function testTheControlFileOfTheSelfCheckLiesBesideTheStoreAndGoesAgain(): void {
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		touch( self::root() . '/run/apache-' . basename( AB_MCP_IT_Sites::docroot( 'a' ) ) );
		try {
			$during  = array();
			$fetcher = \AB_MCP_Test_Sync_Fetcher::for_sites();
			AB_MCP_Test_Seam::install(
				AB_MCP_Protected_Store::class,
				self::SEAM,
				'AB_MCP_Protected_Store_Calls',
				array(
					array(
						'method' => 'ask',
						'nth'    => 1,
						'kind'   => 'before',
						'before' => static function () use ( &$during, $uploads ): void {
							$during = self::names( $uploads );
						},
					),
				)
			);
			AB_MCP_Protected_Store::forget();
			$proof = AB_MCP_Protected_Store::proof( $fetcher, true );
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( 'uploads', $proof['where'], (string) $proof['message'] );
			$probe = array_values( preg_grep( '/^ab-mcp-probe-[0-9a-f]{16}\.txt\z/', $during ) );
			self::assertCount( 1, $probe, 'While it is asked the control file lies beside the store.' );
			self::assertSame( array(), preg_grep( '/\.tmp\z/', $during ), 'Its temp file is renamed already.' );
			$after = self::names( $uploads );
			self::assertSame( array(), preg_grep( '/^ab-mcp-probe-|\.tmp\z|^\.ab-tmp-/', $after ), 'Afterwards nothing of the check is beside the store.' );
			$store = (string) AB_MCP_Protected_Store::root();
			foreach ( array( '', '/out', '/backups', '/stage' ) as $at ) {
				self::assertSame( array(), preg_grep( '/^ab-probe-/', self::names( $store . $at ) ), "Nor in the store$at." );
			}
			self::assertSame( array(), self::names( $store . '/tmp' ) );
		} finally {
			unlink( self::root() . '/run/apache-' . basename( AB_MCP_IT_Sites::docroot( 'a' ) ) );
		}
	}

	public function testAnUndoPointIsWrittenBesideItsTargetWhateverTheStoreIs(): void {
		$write = new ReflectionMethod( AB_MCP_Undo::class, 'payload_write' );
		$root  = self::store();
		// The store not writable, and then no store at all.
		chmod( $root, 0500 );
		try {
			$one = $write->invoke( null, AB_MCP_Undo::new_token(), serialize( array( 'post' => 1 ) ) );
		} finally {
			chmod( $root, 0700 );
		}
		self::wipe();
		unset( $_SERVER['DOCUMENT_ROOT'] );
		AB_MCP_Protected_Store::forget();
		self::assertFalse( AB_MCP_Protected_Store::available() );
		$two = $write->invoke( null, AB_MCP_Undo::new_token(), serialize( array( 'post' => 2 ) ) );
		$dir = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads/ab-mcp-undo';
		foreach ( array( $one, $two ) as $made ) {
			self::assertIsArray( $made );
			self::assertFileExists( $dir . '/' . $made['file'] );
		}
		self::assertSame( array(), preg_grep( '/\.tmp\z/', self::names( $dir ) ), 'Its temp file lay beside the target and went.' );
		self::assertFileDoesNotExist( self::root() . '/ab-mcp-store', 'The store is not touched.' );
		foreach ( array( $one, $two ) as $made ) {
			unlink( $dir . '/' . $made['file'] );
		}
	}

	/* ------------- Behebung 1: unreadable is never empty, missing or foreign */

	/**
	 * A sidecar changed as a case asks, a day and more old: bytes of its own,
	 * or its entry with fields changed (null: the field taken out).
	 *
	 * @param string       $side   The sidecar.
	 * @param string|array $change Bytes, or field => value.
	 */
	private static function sidecar_as( string $side, $change ): void {
		if ( is_array( $change ) ) {
			$entry = json_decode( (string) file_get_contents( $side ), true );
			foreach ( $change as $key => $value ) {
				if ( null === $value ) {
					unset( $entry[ $key ] );
				} else {
					$entry[ $key ] = $value;
				}
			}
			$change = (string) json_encode( $entry, JSON_UNESCAPED_SLASHES );
		}
		file_put_contents( $side, $change );
		touch( $side, time() - 2 * 86400 );
	}

	/** The store's seam with read errors of the paths named (AB_MCP_IT_Store_Calls::$fail). */
	private static function failing( array $fail ): AB_MCP_IT_Store_Calls {
		$calls       = new AB_MCP_IT_Store_Calls();
		$calls->fail = $fail;
		AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, $calls );
		return $calls;
	}

	public function testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps(): void {
		// A1 (V2): run 4 is one of the last three qualified closes; its swaps
		// are over (their marks closed), so its backups stay by the retention
		// alone, whatever their age (decision 20).
		$now = time();
		self::run_row( 4, 'closed_done', $now - 10 * 86400, $now - 10 * 86400 );
		$cases = array(
			'no JSON'                                   => '{"format":',
			'empty, as write_entry() writes it where json_encode() fails' => '',
			'not of the format'                         => array( 'format' => 'ab-mcp-other/9' ),
			'its time made no number'                   => array(
				'run_id'  => 0,
				'created' => 'yesterday',
			),
			'without the time it was made'              => array( 'created' => null ),
			'its run no number'                         => array(
				'run_id'  => 'vier',
				'created' => $now - 30 * 86400,
			),
			'an area the store has not'                 => array( 'area' => 'elsewhere' ),
			'another area, kept by the one it lies in'  => array(
				'area'    => 'out',
				'created' => $now - 30 * 86400,
			),
			'another installation, kept by its run'     => array( 'gid' => str_repeat( 'f', 32 ) ),
		);
		$made  = array();
		foreach ( $cases as $label => $change ) {
			$m = $this->marked( 4, 'closed' );
			self::sidecar_as( $m['side'], $change );
			$made[ $label ] = $m;
		}
		// A group of reports whose entry names a backup of run 4: kept by the
		// area it names.
		$rep_gid = AB_MCP_Protected_Store::open( 'reports', array( 'run_id' => 4 ) );
		self::assertIsString( $rep_gid );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'reports', $rep_gid, 'post-1', 'eine Zeile' ) );
		$rep_dir = $made['no JSON']['root'] . '/reports/' . AB_MCP_Payload_File::name( 'group|reports|' . $rep_gid, $made['no JSON']['id'], '' );
		self::sidecar_as(
			$rep_dir . '.json',
			array(
				'area'    => 'backups',
				'created' => $now - 30 * 86400,
			)
		);
		$unreadable = $this->marked( 4, 'closed' );
		touch( $unreadable['side'], $now - 2 * 86400 );
		$posix_root = function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
		if ( ! $posix_root ) {
			chmod( $unreadable['side'], 0000 );
			$made['without read rights'] = $unreadable;
		}
		$bytes = array();
		foreach ( $made as $label => $m ) {
			$bytes[ $label ] = 'without read rights' === $label ? null : (string) file_get_contents( $m['side'] );
		}
		try {
			AB_MCP_Protected_Store::cleanup( $now );
		} finally {
			chmod( $unreadable['side'], 0600 );
		}
		foreach ( $made as $label => $m ) {
			self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ), "$label: the backup stays byte for byte." );
			self::assertFileExists( $m['side'], "$label: its sidecar stays." );
			if ( null !== $bytes[ $label ] ) {
				self::assertSame( $bytes[ $label ], (string) file_get_contents( $m['side'] ), "$label: as it was." );
			}
		}
		self::assertSame( array( 'post-1' ), self::names( $rep_dir ), 'another area, kept by the one it names: the group of reports stays.' );
	}

	public function testASidecarWhoseReadFailsKeepsItsGroup(): void {
		// B5: the read of the store's seam fails for the sidecar alone; the
		// retention of run 4 is over, so only the read error keeps it.
		$now = time();
		self::run_row( 4, 'closed_done', $now - 10 );
		$m = $this->marked( 4, 'closed' );
		touch( $m['side'], $now - 2 * 86400 );
		$calls = self::failing( array( 'read' => array( $m['side'] ) ) );
		AB_MCP_Protected_Store::cleanup( $now );
		AB_MCP_Test_Seam::restore_all();
		self::assertContains( array( 'read', $m['side'] ), $calls->failed, 'The read of the sidecar failed.' );
		self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ) );
		self::assertFileExists( $m['side'] );
		// Read again, it goes by its retention.
		AB_MCP_Protected_Store::cleanup( $now );
		self::assertFileDoesNotExist( $m['group'] );
	}

	public function testAForeignSidecarTheRetentionLetsGoGoesWithItsGroupAfterADay(): void {
		// The normal case beside A1: read whole, another installation's, of a
		// run without a row and older than backups are kept by their age.
		$now = time();
		$old = $this->marked( 9, 'closed' );
		self::sidecar_as(
			$old['side'],
			array(
				'gid'     => str_repeat( 'f', 32 ),
				'created' => $now - 30 * 86400,
			)
		);
		$young = $this->marked( 9, 'closed' );
		self::sidecar_as( $young['side'], array( 'gid' => str_repeat( 'e', 32 ) ) );
		touch( $young['side'], $now - 3600 );
		AB_MCP_Protected_Store::cleanup( $now );
		self::assertFileDoesNotExist( $old['group'], 'Its group goes.' );
		self::assertFileDoesNotExist( $old['side'], 'And its sidecar.' );
		self::assertSame( $young['kept']['tree'], self::snapshot( $young['tree'] ), 'A foreign sidecar of today stays.' );
	}

	public function testAFolderWhoseSidecarIsListedIsNoFolderWithoutItsSidecarWhateverItsLstatSays(): void {
		// A1: absent is only what the listing of the area lacks.
		$m = $this->marked( 4, 'closed' );
		touch( $m['group'], time() - 2 * 86400 );
		$calls = self::failing( array( 'lstat' => array( $m['side'] ) ) );
		$done  = AB_MCP_Protected_Store::cleanup( time() );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ) );
		self::assertFileExists( $m['side'] );
		// Behebung 3: a folder without its sidecar stays and counts unknown;
		// this one has its sidecar, so nothing counts.
		self::assertSame( 0, $done['unknown'] );
	}

	public function testAnAreaWhoseEntriesCannotBeStatedLosesNothing(): void {
		// B5: an lstat() outside the seam (plain()) that fails: the folder of
		// the area is listed, its entries cannot be stated (no x right).
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'The rights of a folder stop no process of root.' );
		}
		$m = $this->marked( 9, 'closed' );
		self::sidecar_as( $m['side'], array( 'created' => time() - 30 * 86400 ) );
		chmod( $m['root'] . '/backups', 0400 );
		try {
			AB_MCP_Protected_Store::cleanup( time() );
		} finally {
			chmod( $m['root'] . '/backups', 0700 );
		}
		self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ) );
		self::assertFileExists( $m['side'] );
	}

	public function testAGroupWhoseFolderCannotBeStatedIsNotRemovedAndKeepsItsSidecar(): void {
		// A2, the first counter-example of Codex round 1: the lstat() of the
		// folder fails; with an open mark on its tree and without one.
		foreach ( array( 'open', 'closed' ) as $state ) {
			$m     = $this->marked( 3, $state );
			$calls = new AB_MCP_IT_Store_Calls();
			$calls->fail = array( 'lstat' => array( $m['group'] ) );
			AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, $calls );
			$gone = AB_MCP_Protected_Store::remove( 'backups', $m['gid'] );
			AB_MCP_Test_Seam::restore_all();
			self::assertFalse( $gone, "$state: not proved gone, so not removed." );
			self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ), $state );
			self::assertFileExists( $m['side'], "$state: the sidecar stays with its folder." );
		}
	}

	public function testAGroupWhoseTreeAnOpenMarkNamesKeepsItsSidecarAlsoWhereItsFolderIsGone(): void {
		// A2: the guard of the marks holds before a removal counts as done;
		// the retention lets the group go, its folder was taken by hand.
		self::run_row( 3, 'closed_done', time() - 10 );
		$m = $this->marked( 3 );
		self::remove_tree( $m['group'] );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertFileExists( $m['side'], 'The record of the group whose tree the mark names stays.' );
		self::assertSame( $m['kept']['marks'], self::snapshot( $m['marks'] ) );
	}

	public function testAnUninstallThatCannotStateTheBackupsKeepsTheManifestAndTheLock(): void {
		// A2, the second counter-example: the lstat() of backups fails; the
		// uninstall runs in this process, with the read error.
		$m      = $this->marked( 3, 'closed' );
		$before = self::names( $m['root'] );
		self::failing( array( 'lstat' => array( $m['root'] . '/backups' ) ) );
		AB_MCP_Protected_Store::uninstall();
		AB_MCP_Test_Seam::restore_all();
		foreach ( array( 'owner.json', 'store.lock', '.htaccess', 'index.php', 'web.config', 'backups' ) as $name ) {
			self::assertContains( $name, self::names( $m['root'] ), "$name stays: backups were not proved gone." );
		}
		self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ) );
		self::assertNotEmpty( $before );
	}

	/* ------------------------- Behebung 1, B3: the lock of the uninstall */

	/**
	 * A process that holds the lock of a folder of the store, holding when
	 * this returns, for $seconds.
	 *
	 * @return array{0:resource,1:array}
	 */
	private static function hold_lock( string $root, float $seconds ): array {
		$code    = '$h = fopen( $argv[1], "c" ); if ( ! $h || ! flock( $h, LOCK_EX ) ) { exit( 3 ); } echo "held\n"; fflush( STDOUT ); usleep( (int) ( (float) $argv[2] * 1000000 ) ); exit( 0 );';
		$process = proc_open( array( PHP_BINARY, '-r', $code, $root . '/store.lock', (string) $seconds ), array( 1 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		self::assertSame( "held\n", fgets( $pipes[1] ), 'The holder holds the lock.' );
		return array( $process, $pipes );
	}

	/** End the holder (its own process, started by this case). */
	private static function release( array $holder ): void {
		$status = proc_get_status( $holder[0] );
		if ( $status['running'] ) {
			proc_terminate( $holder[0] );
		}
		fclose( $holder[1][1] );
		proc_close( $holder[0] );
	}

	public function testTheUninstallWaitsForTheLockAndWorksOnceItIsFree(): void {
		$m      = $this->marked( 3, 'open', 'missing' );
		$orig   = $m['kept']['tree'];
		$holder = self::hold_lock( $m['root'], 2.0 );
		$start  = microtime( true );
		try {
			self::uninstall_free();
		} finally {
			self::release( $holder );
		}
		self::assertGreaterThan( 1.5, microtime( true ) - $start, 'It waited for the holder.' );
		self::assertSame( $orig, self::snapshot( $m['target'] ), 'Under the lock: the original went back.' );
		self::assertFileDoesNotExist( $m['root'], 'And the folder went.' );
	}

	public function testTheUninstallLeavesAFolderWhoseLockIsHeldPastItsTimeAsItIs(): void {
		$m      = $this->marked( 3, 'open', 'missing' );
		$before = self::snapshot( $m['root'] );
		$holder = self::hold_lock( $m['root'], 15.0 );
		$start  = microtime( true );
		try {
			self::uninstall_free();
			$took = microtime( true ) - $start;
		} finally {
			self::release( $holder );
		}
		self::assertLessThan( 14.0, $took, 'It asked for the lock about ten seconds, not until the holder let go.' );
		self::assertSame( $before, self::snapshot( $m['root'] ), 'Nothing went back and nothing went: marks, backups, instruction and manifest stay for the way back by hand.' );
		self::assertFileDoesNotExist( $m['target'], 'The original was not put back without the lock.' );
	}

	public function testTheUninstallDoesNothingWhereTheLockCannotBeOpened(): void {
		foreach ( array( 'a folder', 'a link' ) as $what ) {
			$m    = $this->marked( 3, 'open', 'missing' );
			$lock = $m['root'] . '/store.lock';
			@unlink( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( 'a folder' === $what ) {
				mkdir( $lock, 0700 );
			} else {
				$aim = self::root() . '/run/ab-store-lock-aim';
				file_put_contents( $aim, '' );
				$this->made[] = $aim;
				symlink( $aim, $lock );
			}
			$before = self::snapshot( $m['root'] );
			$names  = self::names( $m['root'] );
			self::uninstall_free();
			self::assertSame( $names, self::names( $m['root'] ), "$what: every name of the folder stays." );
			self::assertSame( $before, self::snapshot( $m['root'] ), "$what: nothing went back and nothing went without the lock." );
			self::assertFileDoesNotExist( $m['target'], $what );
			if ( 'a folder' === $what ) {
				rmdir( $lock );
			} else {
				unlink( $lock );
			}
			self::wipe();
			AB_MCP_Protected_Store::forget();
			self::assertTrue( AB_MCP_Protected_Store::available() );
		}
	}

	/* ---------------------------- Behebung 1, B5: the identity by its hash */

	public function testTheUninstallPutsBackABackupWhoseHashIsTheMarksAndNoneWhoseFilesChanged(): void {
		$m    = $this->marked( 3, 'open', 'missing', true );
		$orig = $m['kept']['tree'];
		self::uninstall_free();
		self::assertSame( $orig, self::snapshot( $m['target'] ), 'Device, inode and hash as the mark says: put back.' );
		self::remove_tree( $m['target'] );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		self::assertTrue( AB_MCP_Protected_Store::available() );
		// The same folder (device, inode), a file in it changed.
		$m = $this->marked( 3, 'open', 'missing', true );
		file_put_contents( $m['tree'] . '/data/export.csv', "id;wert\n1;geaendert\n" );
		$changed = self::snapshot( $m['tree'] );
		self::uninstall_free();
		self::assertFileDoesNotExist( $m['target'], 'Its files are not the ones the mark names: not put back.' );
		self::assertSame( $changed, self::snapshot( $m['tree'] ), 'It stays where it is.' );
		self::assertSame( $m['kept']['marks'], self::snapshot( $m['marks'] ), 'With its mark.' );
	}

	/* -------------- Behebung 2: the table of the sidecar, no value made up */

	/** A field taken out of a sidecar (bad_values()). */
	private const MISSING = "\0missing";

	/**
	 * The table of the sidecar as Behebung 2 describes it, written out here
	 * and not read from the store, so the cases do not follow a change of
	 * AB_MCP_Protected_Store::SIDECAR: every field open() writes, in every
	 * area; run_id 0 or more in out, in and reports, 1 or more in backups
	 * and stage.
	 */
	private const ROWS = array(
		'out'     => array( 'format' => 'format', 'gid' => 'gid', 'area' => 'area', 'created' => 'time', 'user_id' => 'count', 'run_id' => 'count', 'declared' => 'count', 'used' => 'count', 'label' => 'label', 'closed' => 'bool', 'binding' => 'text' ),
		'in'      => array( 'format' => 'format', 'gid' => 'gid', 'area' => 'area', 'created' => 'time', 'user_id' => 'count', 'run_id' => 'count', 'declared' => 'count', 'used' => 'count', 'label' => 'label', 'closed' => 'bool', 'binding' => 'text' ),
		'reports' => array( 'format' => 'format', 'gid' => 'gid', 'area' => 'area', 'created' => 'time', 'user_id' => 'count', 'run_id' => 'count', 'declared' => 'count', 'used' => 'count', 'label' => 'label', 'closed' => 'bool', 'binding' => 'text' ),
		'backups' => array( 'format' => 'format', 'gid' => 'gid', 'area' => 'area', 'created' => 'time', 'user_id' => 'count', 'run_id' => 'run', 'declared' => 'count', 'used' => 'count', 'label' => 'label', 'closed' => 'bool', 'binding' => 'text' ),
		'stage'   => array( 'format' => 'format', 'gid' => 'gid', 'area' => 'area', 'created' => 'time', 'user_id' => 'count', 'run_id' => 'run', 'declared' => 'count', 'used' => 'count', 'label' => 'label', 'closed' => 'bool', 'binding' => 'text' ),
	);

	/**
	 * The values of a field of a type of the table (ROWS) a
	 * sidecar must not hold: missing, null, of another type, and outside its
	 * range where the type has one.
	 *
	 * @return array<string,mixed>
	 */
	private static function bad_values( string $type ): array {
		$out = array(
			'missing'      => self::MISSING,
			'null'         => null,
			'another type' => in_array( $type, array( 'time', 'count', 'run' ), true ) ? '5' : ( 'bool' === $type ? 1 : 7 ),
		);
		$range = array(
			'format' => array( 'ab-mcp-store/9' ),
			'gid'    => array( str_repeat( 'g', 32 ) ),
			'area'   => array( 'elsewhere' ),
			'time'   => array( 0, -5 ),
			'count'  => array( -1 ),
			'run'    => array( 0, -1 ),
			'label'  => array( 'ein Etikett?' ),
		);
		foreach ( $range[ $type ] ?? array() as $n => $value ) {
			$out[ 'outside its range ' . ( $n + 1 ) ] = $value;
		}
		return $out;
	}

	/** Whether a value is of a type of the table, by its description (independent of the store's own check). */
	private static function of_type( $value, string $type, string $area ): bool {
		switch ( $type ) {
			case 'format':
				return 'ab-mcp-store/1' === $value;
			case 'gid':
				return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{32}$/D', $value );
			case 'area':
				return $area === $value;
			case 'time':
				return is_int( $value ) && $value > 0;
			case 'count':
				return is_int( $value ) && $value >= 0;
			case 'run':
				return is_int( $value ) && $value >= 1;
			case 'label':
				return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{0,191}$/D', $value );
			case 'text':
				return is_string( $value );
			case 'bool':
				return is_bool( $value );
		}
		return false;
	}

	/** The sidecar of a group of this installation, as it lies on disk. */
	private static function sidecar_of( string $area, string $gid ): array {
		$side = self::store() . '/' . $area . '/' . AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, AB_MCP_Protected_Store::install_id(), '' ) . '.json';
		self::assertFileExists( $side );
		return (array) json_decode( (string) file_get_contents( $side ), true );
	}

	/** Every field of the area's row there, of its type and in its range. */
	private static function assert_row( array $entry, string $area, string $why ): void {
		foreach ( self::ROWS[ $area ] as $field => $type ) {
			self::assertArrayHasKey( $field, $entry, "$why: $field is set." );
			self::assertTrue( self::of_type( $entry[ $field ], $type, $area ), "$why: $field is $type." );
		}
	}

	/**
	 * A group made by hand as open() makes it, with a file in it: a folder
	 * of the name of a new gid and its sidecar, the entry as given.
	 *
	 * @return array{dir:string,side:string,bytes:string}
	 */
	private static function group_as( string $area, array $entry ): array {
		$gid  = bin2hex( random_bytes( 16 ) );
		$dir  = self::store() . '/' . $area . '/' . AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, AB_MCP_Protected_Store::install_id(), '' );
		$keep = array_key_exists( 'gid', $entry ) && is_string( $entry['gid'] ) && 1 === preg_match( '/^[0-9a-f]{32}$/D', $entry['gid'] );
		if ( $keep ) {
			$entry['gid'] = $gid;
		}
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/post-1', 'eine Zeile' );
		$bytes = (string) json_encode( $entry, JSON_UNESCAPED_SLASHES );
		file_put_contents( $dir . '.json', $bytes );
		touch( $dir . '.json', time() - 2 * 86400 );
		return array(
			'dir'   => $dir,
			'side'  => $dir . '.json',
			'bytes' => $bytes,
		);
	}

	/**
	 * For each area its sidecar as open() writes it, made 30 days ago: of
	 * run 9, closed and past its retention (not kept by its run), where the
	 * area knows groups of a run only; of no run elsewhere.
	 *
	 * @return array<string,array>
	 */
	private static function templates( int $now ): array {
		self::run_row( 9, 'closed_done', $now - 10 );
		$meta = array(
			'out'     => array( 'declared' => 100, 'label' => 'series:abc' ),
			'in'      => array( 'declared' => 100, 'label' => 'import:abc', 'binding' => 'print' ),
			'reports' => array( 'label' => 'report' ),
			'backups' => array( 'run_id' => 9, 'label' => 'backup:9' ),
			'stage'   => array( 'run_id' => 9 ),
		);
		$out  = array();
		foreach ( $meta as $area => $m ) {
			$gid = AB_MCP_Protected_Store::open( $area, $m );
			self::assertIsString( $gid, $area );
			$entry = self::sidecar_of( $area, $gid );
			self::assert_row( $entry, $area, "open() in $area" );
			$entry['created'] = $now - 30 * 86400;
			$out[ $area ]     = $entry;
			self::assertTrue( AB_MCP_Protected_Store::remove( $area, $gid ) );
		}
		self::assertSame( array_keys( self::ROWS ), array_keys( $out ), 'A template for every area of the table.' );
		return $out;
	}

	public function testASidecarThatLacksAFieldOfItsRowOrHoldsItNullOrOfAnotherTypeOrRangeKeepsItsGroup(): void {
		// Behebung 2, A1: for every field of every area's row the cases
		// missing, null, another type and outside its range; each group
		// would go if its sidecar were whole (the control per area).
		$now      = time();
		$template = self::templates( $now );
		$cases    = array();
		$controls = array();
		foreach ( self::ROWS as $area => $row ) {
			$controls[ $area ] = self::group_as( $area, $template[ $area ] );
			foreach ( $row as $field => $type ) {
				foreach ( self::bad_values( $type ) as $case => $value ) {
					$entry = $template[ $area ];
					if ( self::MISSING === $value ) {
						unset( $entry[ $field ] );
					} else {
						$entry[ $field ] = $value;
					}
					$cases[ "$area: $field $case" ] = self::group_as( $area, $entry );
				}
			}
		}
		$done = AB_MCP_Protected_Store::cleanup( $now );
		foreach ( $controls as $area => $group ) {
			self::assertFileDoesNotExist( $group['dir'], "$area: whole and past its time, the control goes." );
			self::assertFileDoesNotExist( $group['side'], "$area: with its sidecar." );
		}
		foreach ( $cases as $label => $group ) {
			self::assertSame( array( 'post-1' ), self::names( $group['dir'] ), "$label: the group stays." );
			self::assertSame( $group['bytes'], (string) file_get_contents( $group['side'] ), "$label: its sidecar as it was." );
		}
		self::assertGreaterThan( 200, count( $cases ) );
		self::assertSame( count( $cases ), $done['unknown'], 'The clean-up names the groups it kept for their sidecar.' );
		self::assertSame( count( $controls ), $done['removed'] );
		self::assertSame( self::ROWS, AB_MCP_Protected_Store::SIDECAR, 'The table of the store is the one these cases check.' );
	}

	public function testABackupWhoseSidecarLacksItsRunStaysAlsoPastItsAge(): void {
		// The case of the confirmation of Behebung 1: a closed mark, a backup
		// of one of the last three qualified closes, its sidecar without
		// run_id, made 30 days ago.
		$now = time();
		self::run_row( 4, 'closed_done', $now - 10 * 86400, $now - 10 * 86400 );
		$m = $this->marked( 4, 'closed' );
		self::sidecar_as(
			$m['side'],
			array(
				'run_id'  => null,
				'created' => $now - 30 * 86400,
			)
		);
		$bytes = (string) file_get_contents( $m['side'] );
		$done  = AB_MCP_Protected_Store::cleanup( $now );
		self::assertSame( $m['kept']['tree'], self::snapshot( $m['tree'] ), 'The backup stays byte for byte.' );
		self::assertSame( $bytes, (string) file_get_contents( $m['side'] ) );
		self::assertSame( 1, $done['unknown'] ?? null );
	}

	public function testARunOfMinusOneOrAsAStringKeepsTheGroup(): void {
		// Behebung 2, point 3: a run outside its range is unknown, never 0.
		$now = time();
		self::run_row( 4, 'closed_done', $now - 10 * 86400, $now - 10 * 86400 );
		$groups = array();
		$made   = array();
		// Every group made first: a package is not opened while a sidecar of
		// out or in cannot be read (Behebung 4: its budget is not known).
		foreach ( array( 'minus one' => -1, 'a string' => '4' ) as $what => $run ) {
			$m  = $this->marked( 4, 'closed' );
			$in = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 10 ) );
			self::assertIsString( $in );
			$dir             = $m['root'] . '/in/' . AB_MCP_Payload_File::name( 'group|in|' . $in, $m['id'], '' );
			$made[ $what ]   = array( $run, $m, $dir );
		}
		foreach ( $made as $what => list( $run, $m, $dir ) ) {
			foreach ( array( $m['side'], $dir . '.json' ) as $side ) {
				self::sidecar_as(
					$side,
					array(
						'run_id'  => $run,
						'created' => $now - 30 * 86400,
					)
				);
			}
			$groups[ "backups, $what" ] = array( $m['group'], $m['side'] );
			$groups[ "in, $what" ]      = array( $dir, $dir . '.json' );
		}
		AB_MCP_Protected_Store::cleanup( $now );
		foreach ( $groups as $label => $paths ) {
			self::assertDirectoryExists( $paths[0], "$label: the group stays." );
			self::assertFileExists( $paths[1], $label );
		}
	}

	public function testEveryWriterOfASidecarSetsItsRowAndARunOnlyWhereTheTableHoldsIt(): void {
		// The writers of sidecars: open() as the shipped callers call it in
		// each area, put(), bind() and close() (they write the entry they
		// read with one field changed), and AB_MCP_Sync_Backup::group().
		$gids = self::templates_kept();
		self::assertIsArray( AB_MCP_Protected_Store::put( 'out', $gids['out'], 'chunk-1', 'x' ) );
		self::assert_row( self::sidecar_of( 'out', $gids['out'] ), 'out', 'put()' );
		self::assertTrue( AB_MCP_Protected_Store::bind( 'in', $gids['in'], 7 ) );
		$bound = self::sidecar_of( 'in', $gids['in'] );
		self::assert_row( $bound, 'in', 'bind()' );
		self::assertSame( 7, $bound['run_id'], 'bound later' );
		self::assertTrue( AB_MCP_Protected_Store::bind( 'in', $gids['in'], 0 ), 'Unbound again: in knows groups of no run.' );
		self::assertSame( 0, self::sidecar_of( 'in', $gids['in'] )['run_id'] );
		self::assertTrue( AB_MCP_Protected_Store::close( 'in', $gids['in'] ) );
		self::assert_row( self::sidecar_of( 'in', $gids['in'] ), 'in', 'close()' );
		$backup = \AB_MCP_Sync_Backup::group( 8 );
		self::assertIsString( $backup );
		$entry = self::sidecar_of( 'backups', $backup );
		self::assert_row( $entry, 'backups', 'AB_MCP_Sync_Backup::group()' );
		self::assertSame( 8, $entry['run_id'] );

		// A run the table does not hold is refused, never made 0; nothing is written.
		$before = array();
		foreach ( array_keys( self::ROWS ) as $area ) {
			$before[ $area ] = self::names( self::store() . '/' . $area );
		}
		foreach ( array(
			'backups without a run' => array( 'backups', array() ),
			'backups of run 0'      => array( 'backups', array( 'run_id' => 0 ) ),
			'stage without a run'   => array( 'stage', array() ),
			'out of run -1'         => array( 'out', array( 'run_id' => -1, 'declared' => 10 ) ),
			'in of run "7"'         => array( 'in', array( 'run_id' => '7', 'declared' => 10 ) ),
			'in of run null'        => array( 'in', array( 'run_id' => null, 'declared' => 10 ) ),
			'reports of run 1.5'    => array( 'reports', array( 'run_id' => 1.5 ) ),
		) as $what => $call ) {
			$res = AB_MCP_Protected_Store::open( $call[0], $call[1] );
			self::assertInstanceOf( \WP_Error::class, $res, $what );
			self::assertSame( 'ab_mcp_store_run', $res->get_error_code(), $what );
		}
		foreach ( array(
			'in, -1'      => array( 'in', $gids['in'], -1 ),
			'in, "7"'     => array( 'in', $gids['in'], '7' ),
			'backups, 0'  => array( 'backups', $backup, 0 ),
			'backups, -3' => array( 'backups', $backup, -3 ),
		) as $what => $call ) {
			$res = AB_MCP_Protected_Store::bind( $call[0], $call[1], $call[2] );
			self::assertInstanceOf( \WP_Error::class, $res, "bind $what" );
			self::assertSame( 'ab_mcp_store_run', $res->get_error_code(), "bind $what" );
		}
		foreach ( array_keys( self::ROWS ) as $area ) {
			self::assertSame( $before[ $area ], self::names( self::store() . '/' . $area ), "$area: nothing written for a run refused." );
		}
		self::assertSame( 8, self::sidecar_of( 'backups', $backup )['run_id'], 'The backup keeps its run.' );
		self::assertSame( 0, self::sidecar_of( 'in', $gids['in'] )['run_id'] );
	}

	/** The groups of the writers, as the shipped callers open them, kept by their age. */
	private static function templates_kept(): array {
		$out = array();
		foreach ( array(
			'out'     => array( 'declared' => 100, 'label' => 'series:abc' ),
			'in'      => array( 'declared' => 100, 'label' => 'import:abc', 'binding' => 'print' ),
			'reports' => array( 'label' => 'report' ),
			'backups' => array( 'run_id' => 7, 'label' => 'backup:7' ),
			'stage'   => array( 'run_id' => 7 ),
		) as $area => $meta ) {
			$gid = AB_MCP_Protected_Store::open( $area, $meta );
			self::assertIsString( $gid, $area );
			$entry = self::sidecar_of( $area, $gid );
			self::assert_row( $entry, $area, "open() in $area" );
			self::assertSame( $meta['run_id'] ?? 0, $entry['run_id'], "$area: the run as given, 0 where none." );
			$out[ $area ] = $gid;
		}
		return $out;
	}

	public function testGroupsOfNoRunGoByTheirTimeAndAPackageBoundLaterStaysWhileItsRunIsOpen(): void {
		// Behebung 2, point 4: run_id 0 is valid where the table knows groups
		// of no run; no group of them is held by the table.
		$now = time();
		self::run_row( 7, 'open' );
		$gids = self::templates_kept();
		$bound = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 10 ) );
		self::assertIsString( $bound );
		self::assertTrue( AB_MCP_Protected_Store::bind( 'in', $bound, 7 ) );
		$done = AB_MCP_Protected_Store::cleanup( $now + 2 * 86400 );
		self::assertSame( 0, $done['unknown'], 'No sidecar the writers wrote is unknown.' );
		foreach ( array( 'out', 'in', 'reports' ) as $area ) {
			self::assertFalse( self::group_there( $area, $gids[ $area ] ), "$area of no run: gone after a day." );
		}
		self::assertTrue( self::group_there( 'in', $bound ), 'The package bound later stays while its run is open.' );
		self::assertTrue( self::group_there( 'backups', $gids['backups'] ), 'A backup of the open run stays.' );
		self::assertTrue( self::group_there( 'stage', $gids['stage'] ), 'The stage of the open run stays.' );
	}

	/** Whether the folder of a group of this installation is there. */
	private static function group_there( string $area, string $gid ): bool {
		return is_dir( self::store() . '/' . $area . '/' . AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, AB_MCP_Protected_Store::install_id(), '' ) );
	}

	public function testAClosedMarkWhoseTimeOfClosingIsNoNumberStays(): void {
		// Behebung 2, the enumeration of sweep(): the age of a closed mark
		// only from a time it holds as a whole number above 0.
		$m    = $this->marked( 3, 'closed' );
		$mark = json_decode( (string) file_get_contents( $m['marks'] . '/mark.json' ), true );
		foreach ( array( 'gestern', null, 0 ) as $closed ) {
			$mark['closed'] = $closed;
			file_put_contents( $m['marks'] . '/mark.json', json_encode( $mark, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			AB_MCP_Protected_Store::cleanup( time() );
			self::assertDirectoryExists( $m['marks'], 'closed ' . json_encode( $closed ) . ': the mark stays.' );
		}
		$mark['closed'] = time() - 2 * 86400;
		file_put_contents( $m['marks'] . '/mark.json', json_encode( $mark, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertFileDoesNotExist( $m['marks'], 'Closed two days ago: it goes.' );
	}

	public function testABackupGroupTakesItsRunAsGivenAndNeverAGroupWhoseSidecarIsUnknown(): void {
		// Behebung 2, the list of substitute values: AB_MCP_Sync_Backup::group()
		// takes a whole number of 1 or more, or its decimal digits alone;
		// anything else is refused with its way, never cast to another run.
		$given = \AB_MCP_Sync_Backup::group( 31 );
		self::assertIsString( $given );
		self::assertSame( $given, \AB_MCP_Sync_Backup::group( '31' ), 'The digits of the run name the same run.' );
		$before = self::names( self::store() . '/backups' );
		foreach ( array( 5.7, 31.0, '5abc', '31abc', ' 31', '31 ', '031', '05', '5.0', '+31', '-31', '0', '', '99999999999999999999', 0, -1, null, true, array( 31 ) ) as $run ) {
			$res = \AB_MCP_Sync_Backup::group( $run );
			self::assertInstanceOf( \WP_Error::class, $res, 'run ' . var_export( $run, true ) );
			self::assertSame( 'ab_mcp_sync_backup_run', $res->get_error_code(), 'run ' . var_export( $run, true ) );
			self::assertStringContainsString( 'Pass its run_id', $res->get_error_message() );
		}
		self::assertSame( $before, self::names( self::store() . '/backups' ), 'Nothing written for a run refused.' );
		// A group of the run whose sidecar is unknown (a field missing) is
		// not used again: groups() leaves it out (read_entry(), SIDECAR), and
		// group() keeps no memory of it from the calls above in this request
		// (Behebung 3: before, a cache returned it unchecked).
		self::assertSame( $given, \AB_MCP_Sync_Backup::group( 31 ), 'Asked again in the same request: the same group.' );
		$side = self::store() . '/backups/' . AB_MCP_Payload_File::name( 'group|backups|' . $given, AB_MCP_Protected_Store::install_id(), '' ) . '.json';
		$entry = json_decode( (string) file_get_contents( $side ), true );
		self::assertSame( \AB_MCP_Sync_Backup::LABEL . '31', $entry['label'] );
		unset( $entry['closed'] );
		file_put_contents( $side, json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
		$bytes = (string) file_get_contents( $side );
		self::assertNotContains( $given, array_column( (array) AB_MCP_Protected_Store::groups( 'backups' ), 'gid' ), 'groups() leaves the unknown group out.' );
		$again = \AB_MCP_Sync_Backup::group( 31 );
		self::assertIsString( $again );
		self::assertNotSame( $given, $again, 'A new group of the run, not the unknown one.' );
		self::assertSame( $bytes, (string) file_get_contents( $side ), 'The unknown group stays as it is.' );
		self::assertSame( $again, \AB_MCP_Sync_Backup::group( 31 ), 'And the new one is the group of the run from now on.' );
		$fits = \AB_MCP_Sync_Backup::fits( '31abc', array( 'x' => 1 ) );
		self::assertInstanceOf( \WP_Error::class, $fits, 'fits() takes the run as group() does.' );
		self::assertSame( 'ab_mcp_sync_backup_run', $fits->get_error_code() );
	}

	/* -------- Behebung 3: every input of the retention decision whole or unknown */

	/** A run row of site A with its plan as stored (null: no plan), the fields as the writers set them. */
	private static function run_planned( int $run, string $type, string $state, $plan, int $keep_until = 0, int $qualified = 0 ): void {
		global $wpdb;
		$wpdb->insert(
			AB_MCP_Sync_Schema::table( 'runs' ),
			array(
				'run_id'            => $run,
				'type'              => $type,
				'state'             => $state,
				'keep_until'        => $keep_until,
				'forward_closed_at' => $qualified,
				'plan'              => $plan,
			)
		);
		AB_MCP_Sync_Schema::forget();
	}

	/** A group of an area with a file, of a run (0: none), made $age seconds ago; its folder. */
	private static function aged_group( string $area, int $run, int $age, array $meta = array() ): array {
		$meta += array(
			'out'     => array( 'declared' => 100, 'label' => 'series:abc' ),
			'in'      => array( 'declared' => 100, 'label' => 'import:abc', 'binding' => 'print' ),
			'reports' => array( 'label' => 'report' ),
			'backups' => array( 'label' => 'backup:' . $run ),
			'stage'   => array(),
		)[ $area ];
		$gid = AB_MCP_Protected_Store::open( $area, array( 'run_id' => $run ) + $meta );
		self::assertIsString( $gid, $area );
		self::assertIsArray( AB_MCP_Protected_Store::put( $area, $gid, 'post-1', 'eine Zeile' ) );
		$group = array(
			'gid' => $gid,
			'dir' => self::store() . '/' . $area . '/' . AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, AB_MCP_Protected_Store::install_id(), '' ),
		);
		self::age( $group, $age );
		return $group;
	}

	/** The time a group was made, set $age seconds back (its sidecar; open() sweeps, so after the last open()). */
	private static function age( array $group, int $age ): void {
		$entry            = json_decode( (string) file_get_contents( $group['dir'] . '.json' ), true );
		$entry['created'] = time() - $age;
		file_put_contents( $group['dir'] . '.json', json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
	}

	public function testAWayBackWhosePlanIsNotWholeKeepsTheBackupsOfEveryRun(): void {
		// The case of the confirmation of the list (Codex, 18:15): run 10 is
		// closed and past its retention; the open way back 11 names it.
		$now = time();
		self::run_planned( 10, 'push', 'closed_aborted', null, $now - 10 );
		// The plan as AB_MCP_Sync_Canon::encode() writes it (keys sorted).
		self::run_planned( 11, 'revert', 'open', '{"items":[],"reverts":[10]}' );
		$backup = self::aged_group( 'backups', 10, 30 * 86400 );
		$done   = AB_MCP_Protected_Store::cleanup( $now );
		self::assertSame( array( 'post-1' ), self::names( $backup['dir'] ), 'A plan whole: run 10 is needed, its backup stays.' );
		self::assertSame( 0, $done['unknown'] );
		global $wpdb;
		$cases = array(
			'no JSON'                         => '{"reverts":',
			'no plan'                         => null,
			'a run as a string'               => '{"reverts":["10"]}',
			'no reverts'                      => '{"items":[]}',
			// Behebung 4, A1 of the confirmation of Behebung 3: an object
			// where the list is, and a key twice.
			'reverts an object of a list key' => '{"reverts":{"0":11}}',
			'reverts an object'               => '{"reverts":{"k":11}}',
			'reverts twice'                   => '{"reverts":[10],"reverts":[11]}',
			'keys not as encode() sorts them' => '{"reverts":[10],"items":[]}',
		);
		foreach ( $cases as $why => $plan ) {
			$wpdb->update( AB_MCP_Sync_Schema::table( 'runs' ), array( 'plan' => $plan ), array( 'run_id' => 11 ) );
			$done = AB_MCP_Protected_Store::cleanup( $now );
			self::assertSame( array( 'post-1' ), self::names( $backup['dir'] ), "$why: the plan cannot say which runs the way back needs, so every backup stays." );
			self::assertSame( 1, $done['unknown'] ?? null, "$why: counted unknown." );
		}
		// The way back closed: run 10 is no longer needed, its backup goes.
		$wpdb->update( AB_MCP_Sync_Schema::table( 'runs' ), array( 'state' => 'closed_reverted', 'keep_until' => $now - 1 ), array( 'run_id' => 11 ) );
		AB_MCP_Protected_Store::cleanup( $now );
		self::assertFileDoesNotExist( $backup['dir'] );
	}

	public function testARunClosedWhileItsDaysAreNotKnownStoresNoPeriodAndItsBackupsStay(): void {
		// The chain filter → close → stored row → later clean-up (Behebung 3,
		// A2): days of the filter that are no whole number of 0 to 3650.
		$now = time();
		self::run_planned( 12, 'push', 'open', null );
		$backup = self::aged_group( 'backups', 12, 0 );
		$days   = static fn() => 'thirty';
		add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, $days );
		try {
			self::assertTrue( \AB_MCP_Sync_Run::set_state( 12, 'open', 'closed_aborted' ) );
		} finally {
			remove_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, $days );
		}
		$run = \AB_MCP_Sync_Run::get( 12 );
		self::assertIsArray( $run );
		self::assertSame( 0, (int) $run['keep_until'], 'No period made of days not known: 0, kept (N1).' );
		$shown = \AB_MCP_Sync_Run::shown( $run );
		self::assertIsArray( $shown );
		self::assertStringContainsString( 'no stored period', $shown['retention'] ?? '', 'The status of the run says so.' );
		AB_MCP_Protected_Store::cleanup( $now + 40 * 86400 );
		self::assertSame( array( 'post-1' ), self::names( $backup['dir'] ), 'Weeks later its backup stays (before: 7 days made of the days not known).' );
		// The normal case: days known, the period stored, the backup goes after it.
		self::run_planned( 13, 'push', 'open', null );
		$other = self::aged_group( 'backups', 13, 0 );
		self::assertTrue( \AB_MCP_Sync_Run::set_state( 13, 'open', 'closed_aborted' ) );
		self::assertGreaterThan( $now, (int) \AB_MCP_Sync_Run::get( 13 )['keep_until'] );
		self::assertArrayNotHasKey( 'retention', \AB_MCP_Sync_Run::shown( \AB_MCP_Sync_Run::get( 13 ) ) );
		AB_MCP_Protected_Store::cleanup( $now + 8 * 86400 );
		self::assertFileDoesNotExist( $other['dir'], 'A close that does not qualify: after its 7 days.' );
		self::assertSame( array( 'post-1' ), self::names( $backup['dir'] ) );
	}

	public function testATimeOfTheCleanUpThatIsNotKnownRemovesNoGroup(): void {
		// A report of no run (by its age) and a backup of a run whose row
		// lets it go (closed, past its period, no qualified close): neither
		// goes by a time that is not known.
		self::run_row( 10, 'closed_aborted', time() - 10 );
		$report = self::aged_group( 'reports', 0, 0 );
		$backup = self::aged_group( 'backups', 10, 0 );
		self::age( $report, 30 * 86400 );
		self::age( $backup, 30 * 86400 );
		foreach ( array( 'abc', 0, -5, 1.5, '1800000000' ) as $at ) {
			$done = AB_MCP_Protected_Store::cleanup( $at );
			self::assertSame( array( 'post-1' ), self::names( $report['dir'] ), 'now ' . var_export( $at, true ) . ': the report stays.' );
			self::assertSame( array( 'post-1' ), self::names( $backup['dir'] ), 'now ' . var_export( $at, true ) . ': the backup stays.' );
			self::assertSame( 2, $done['unknown'], 'now ' . var_export( $at, true ) . ': both counted unknown.' );
		}
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertFileDoesNotExist( $report['dir'], 'The time known: it goes by its time.' );
		self::assertFileDoesNotExist( $backup['dir'], 'The time known: it goes by the row of its run.' );
	}

	public function testWithoutTheTablesOfTheRunsTheGroupsOfARunStayAndThoseOfNoRunGoByTheirTime(): void {
		$bound   = self::aged_group( 'reports', 7, 0 );
		$free    = self::aged_group( 'reports', 0, 0 );
		$foreign = self::aged_group( 'reports', 7, 0 );
		self::age( $bound, 30 * 86400 );
		self::age( $free, 30 * 86400 );
		// Another installation's sidecar of a group of run 7, written two
		// days ago: it goes only where the retention lets its group go.
		self::sidecar_as(
			$foreign['dir'] . '.json',
			array(
				'gid'     => str_repeat( 'f', 32 ),
				'created' => time() - 30 * 86400,
			)
		);
		$status = new \ReflectionProperty( AB_MCP_Sync_Schema::class, 'status' );
		$status->setValue( null, array( 'available' => false, 'reason' => 'missing' ) );
		try {
			$done = AB_MCP_Protected_Store::cleanup( time() );
		} finally {
			AB_MCP_Sync_Schema::forget();
		}
		self::assertSame( array( 'post-1' ), self::names( $bound['dir'] ), 'A group of a run stays while the runs cannot be read.' );
		self::assertSame( array( 'post-1' ), self::names( $foreign['dir'] ), 'Also the group of a foreign sidecar of a run.' );
		self::assertSame( 2, $done['unknown'], 'Both counted unknown.' );
		self::assertFileDoesNotExist( $free['dir'], 'A group of no run needs no runs: it goes by its time (Behebung 3, B4).' );
	}

	public function testARunOfAStateTheStoreDoesNotKnowKeepsItsGroupsAndEveryBackup(): void {
		$now = time();
		self::run_planned( 13, 'push', 'weird', null );
		self::run_planned( 10, 'push', 'closed_aborted', null, $now - 10 );
		$weird  = self::aged_group( 'reports', 13, 30 * 86400 );
		$backup = self::aged_group( 'backups', 10, 30 * 86400 );
		$done   = AB_MCP_Protected_Store::cleanup( $now );
		self::assertSame( array( 'post-1' ), self::names( $weird['dir'] ), 'Counted as a run that is not closed (open_runs()): its group stays.' );
		self::assertSame( array( 'post-1' ), self::names( $backup['dir'] ), 'The retention of the backups is unknown (backup_rows()): every backup stays.' );
		self::assertSame( 1, $done['unknown'] );
	}

	public function testAFolderWithoutItsSidecarStaysAndIsCountedUnknown(): void {
		// Before Behebung 3 it went after a day, past kept(): a backup of a
		// run the retention keeps may lie in it.
		self::run_row( 4, 'closed_done', time() - 10 * 86400, time() - 10 * 86400 );
		$group = self::aged_group( 'backups', 4, 0 );
		unlink( $group['dir'] . '.json' );
		touch( $group['dir'], time() - 30 * 86400 );
		$done = AB_MCP_Protected_Store::cleanup( time() );
		self::assertSame( array( 'post-1' ), self::names( $group['dir'] ) );
		self::assertSame( 1, $done['unknown'] );
	}

	public function testWithoutTheIdOfTheInstallationOrWithAForeignManifestNoFolderIsSwept(): void {
		$report = self::aged_group( 'reports', 0, 30 * 86400 );
		$root   = self::store();
		update_option( AB_MCP_Protected_Store::OPTION_ID, 'no id', false );
		AB_MCP_Protected_Store::forget();
		self::assertSame( array( 'removed' => 0, 'kept' => 0, 'unknown' => 0 ), AB_MCP_Protected_Store::cleanup( time() ), 'No id: no folder of its own, nothing read or removed.' );
		self::assertSame( array( 'post-1' ), self::names( $report['dir'] ) );
		// No row of the id (read_id() null): the clean-up makes none
		// (install_id( false )) and sweeps nothing.
		delete_option( AB_MCP_Protected_Store::OPTION_ID );
		AB_MCP_Protected_Store::forget();
		self::assertSame( array( 'removed' => 0, 'kept' => 0, 'unknown' => 0 ), AB_MCP_Protected_Store::cleanup( time() ), 'No row of the id: nothing read or removed.' );
		self::assertSame( array( 'post-1' ), self::names( $report['dir'] ) );
		self::assertFalse( get_option( AB_MCP_Protected_Store::OPTION_ID ), 'And no id made by the clean-up.' );
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_Protected_Store::forget();
		$owner = (string) file_get_contents( $root . '/owner.json' );
		file_put_contents( $root . '/owner.json', '{' );
		self::assertSame( array( 'removed' => 0, 'kept' => 0, 'unknown' => 0 ), AB_MCP_Protected_Store::cleanup( time() ), 'A manifest that cannot be read: not this installation\'s folder, nothing removed.' );
		self::assertSame( array( 'post-1' ), self::names( $report['dir'] ) );
		file_put_contents( $root . '/owner.json', $owner );
	}

	public function testASidecarOrAnAreaThatIsNoPlainFileOrFolderIsNotReadAndCountsUnknown(): void {
		// The name of a sidecar on a link: never followed, so nothing says
		// whose the group is; it stays and counts (Behebung 3).
		$report = self::aged_group( 'reports', 0, 30 * 86400 );
		$copy   = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/ab-it-sidecar-copy.json';
		rename( $report['dir'] . '.json', $copy );
		symlink( $copy, $report['dir'] . '.json' );
		$done = AB_MCP_Protected_Store::cleanup( time() );
		self::assertSame( array( 'post-1' ), self::names( $report['dir'] ), 'The group of a linked sidecar stays.' );
		self::assertFileExists( $copy, 'What the link names is never touched.' );
		self::assertSame( 1, $done['unknown'] );
		unlink( $report['dir'] . '.json' );
		rename( $copy, $report['dir'] . '.json' );
		// An area that is a link to a folder outside the store: nothing in
		// it is read or removed, and it counts.
		$area    = self::store() . '/reports';
		$outside = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/ab-it-linked-reports';
		rename( $area, $outside );
		symlink( $outside, $area );
		try {
			$done = AB_MCP_Protected_Store::cleanup( time() );
		} finally {
			unlink( $area );
			rename( $outside, $area );
		}
		self::assertSame( array( 'post-1' ), self::names( $report['dir'] ), 'The group behind the linked area stays.' );
		self::assertSame( 1, $done['unknown'] );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertFileDoesNotExist( $report['dir'], 'Plain again, the report goes by its time.' );
	}

	public function testAnAreaThatCannotBeListedLosesNothingAndIsCountedUnknown(): void {
		$report = self::aged_group( 'reports', 0, 30 * 86400 );
		$calls  = self::failing( array( 'scandir' => array( self::store() . '/reports' ) ) );
		$done   = AB_MCP_Protected_Store::cleanup( time() );
		AB_MCP_Test_Seam::restore_all();
		self::assertContains( array( 'scandir', self::store() . '/reports' ), $calls->failed );
		self::assertSame( array( 'post-1' ), self::names( $report['dir'] ) );
		self::assertSame( 1, $done['unknown'] );
	}

	public function testATempFileOrAFolderOfAMarkWhoseLstatFailsStays(): void {
		$root = self::store();
		@mkdir( $root . '/tmp', 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$temp = $root . '/tmp/.ab-tmp-alt';
		file_put_contents( $temp, 'x' );
		touch( $temp, time() - 3 * 3600 );
		@mkdir( $root . '/marks', 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$half = $root . '/marks/' . str_repeat( 'b', 32 );
		mkdir( $half, 0700 );
		touch( $half, time() - 3 * 86400 );
		// Their folders cannot be listed: nothing in them goes.
		self::failing( array( 'scandir' => array( $root . '/tmp', $root . '/marks' ) ) );
		AB_MCP_Protected_Store::cleanup( time() );
		AB_MCP_Test_Seam::restore_all();
		self::assertFileExists( $temp, 'tmp/ not listed: the temp file stays.' );
		self::assertDirectoryExists( $half, 'marks/ not listed: the folder of the mark stays.' );
		self::failing( array( 'lstat' => array( $temp, $half ) ) );
		AB_MCP_Protected_Store::cleanup( time() );
		AB_MCP_Test_Seam::restore_all();
		self::assertFileExists( $temp, 'No age read: the temp file stays.' );
		self::assertDirectoryExists( $half, 'No age read: the folder of the mark stays.' );
		AB_MCP_Protected_Store::cleanup( time() );
		self::assertFileDoesNotExist( $temp, 'Read again, it goes by its age.' );
		self::assertFileDoesNotExist( $half );
	}

	public function testAClockThatGivesNoTimeStoresNoPeriodAtTheCloseAndTheBackupsStay(): void {
		// Behebung 4, B2/B3 of the confirmation of Behebung 3: the clock of
		// the runs gave '1bad', the cast made it 1, and a qualified close
		// stored the period 1 + 30 days, long past: its backups went.
		$now = time();
		self::run_planned( 14, 'push', 'open', null );
		$backup = self::aged_group( 'backups', 14, 0 );
		$days   = static fn() => 30;
		add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, $days );
		\AB_MCP_Sync_Run::$clock = static fn() => '1bad';
		try {
			self::assertTrue( \AB_MCP_Sync_Run::set_state( 14, 'open', 'closed_done', true ) );
			self::assertNull( \AB_MCP_Sync_Run::now(), 'No time of a clock that gives none.' );
		} finally {
			\AB_MCP_Sync_Run::$clock = null;
			remove_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, $days );
		}
		$run = \AB_MCP_Sync_Run::get( 14 );
		self::assertSame( 'closed_done', $run['state'] );
		self::assertSame( 0, (int) $run['keep_until'], 'No period of a time not known: 0, kept (N1).' );
		self::assertSame( 0, (int) $run['forward_closed_at'], 'And no qualified close of it.' );
		AB_MCP_Protected_Store::cleanup( $now + 40 * 86400 );
		self::assertSame( array( 'post-1' ), self::names( $backup['dir'] ), 'Weeks later its backup stays.' );
	}

	public function testTheCleanUpOfARequestThatLoadedNoRunReadsTheRetentionOfTheBackups(): void {
		// In a request of its own (a cron request, an open()), the plugin has
		// not loaded AB_MCP_Sync_Run; backup_rows() names its states and
		// types (Behebung 3) and loads it itself. Without that the clean-up
		// ended with an error and removed nothing at all.
		$now = time();
		self::run_planned( 10, 'push', 'closed_aborted', null, $now - 10 );
		self::run_planned( 11, 'revert', 'open', '{"items":[],"reverts":[10]}' );
		$backup = self::aged_group( 'backups', 10, 30 * 86400 );
		$report = self::aged_group( 'reports', 0, 30 * 86400 );
		$done   = AB_MCP_IT_Process::calls(
			'a',
			array(
				'calls' => array(
					array(
						'call' => 'class_exists',
						'args' => array( 'AB_MCP_Sync_Run', false ),
						'as'   => 'before',
					),
					array(
						'call' => 'AB_MCP_Protected_Store::cleanup',
						'args' => array( $now ),
						'as'   => 'cleanup',
					),
				),
			)
		)['results'];
		self::assertFalse( $done['before'], 'The case needs a request without the class: the plugin loads it at its start now, so this case shows nothing any more.' );
		self::assertSame( array( 'removed' => 1, 'kept' => 1, 'unknown' => 0 ), $done['cleanup'], 'The report removed; the backup of run 10 kept beyond its age by its run.' );
		self::assertSame( array( 'post-1' ), self::names( $backup['dir'] ), 'The way back 11 needs run 10: its backup stays.' );
		self::assertFileDoesNotExist( $report['dir'], 'The report of no run goes by its time.' );
	}
}
