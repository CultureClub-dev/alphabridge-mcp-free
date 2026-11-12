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
 *   renames of the swap back, lose nothing.
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
use AB_MCP_Payload_File;
use AB_MCP_Protected_Store;
use AB_MCP_Store_Budget;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AB_MCP_Undo;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

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
	 * @return array
	 */
	private function marked( int $run, string $state = 'open', string $target = 'other' ): array {
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
				'hash' => null,
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
	 * @param array[] $faults Further faults of the plan.
	 */
	private static function watch( array $faults = array() ): void {
		if ( ! property_exists( AB_MCP_Protected_Store::class, self::SEAM ) ) {
			return;
		}
		$seen = static function ( string $method, array $args ): void {
			self::$removed[] = (string) $args[0];
		};
		AB_MCP_Test_Seam::install(
			AB_MCP_Protected_Store::class,
			self::SEAM,
			'AB_MCP_Protected_Store_Calls',
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
		file_put_contents( $m['side'], '{"not":"a sidecar"' );
		touch( $m['side'], time() - 2 * 86400 );
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
		file_put_contents( $side_rep, json_encode( $entry ) );
		$plain_dir = $m['root'] . '/backups/' . AB_MCP_Payload_File::name( 'group|backups|' . $plain, $id, '' );
		// Nachgebildet: every read of a mark fails.
		self::watch(
			array(
				array(
					'method' => 'read',
					'kind'   => 'return',
					'value'  => false,
				),
			)
		);
		AB_MCP_Protected_Store::cleanup( time() );
		self::assert_kept( $m, 'mark unreadable: clean-up' );
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
}
