<?php
/**
 * The manifest of a unit (AB_MCP_Sync_File_Manifest, Z8b branch 2; plan
 * 3.1 Z1 and Z2, E1), the one place that gives the hash of a unit:
 *
 * - Z1: equal only by the whole manifest of every file (path, size,
 *   SHA-256, by strcmp(), ab-files/1); mtime never counts; one file more,
 *   less, other bytes, another name: another hash;
 * - Z2: a folder .git, .svn or .hg anywhere (the folder of the unit itself
 *   included), a link (the unit itself included) or a file that is no
 *   regular file locks the unit (code:live_extra here, code:link on
 *   staging) without a hash; no name is left out within a unit; a theme
 *   outside the folder of the themes is locked (code:theme_root) and not
 *   read, unit() told its kind;
 * - the faults of its calls to the outside (plan 8.3, through the seam
 *   naht_sync_file_manifest), each with the promise at its place: a
 *   reading that fails, throws, is cut, sees a writer or runs out of time
 *   gives no hash, with why (never «equal» on a half reading).
 *
 * Kinds of fault and where they apply to reading a unit:
 * - return false (Lesefehler): names(), lstat(), open(), fstat(), read();
 *   theme_root() without a folder of the themes (every theme locked);
 * - Throwable: any call;
 * - half (halb gelesen): read() gives fewer bytes;
 * - before (gleichzeitiger Schreiber): a writer between lstat() and open()
 *   (another file at the path, or the same file written anew in place), a
 *   rename over the path after the file was opened, and between the reads
 *   (the size or the time changes); each of device, inode, size, mtime and
 *   ctime that differs between lstat() before, fstat() before and after and
 *   lstat() after the reading, by a stand-in of the calls, so that every
 *   file system checks the same, whatever inode it gives a new file
 *   (Behebung 2: the CI reused the inode of a file removed and written anew);
 * - Zeitlimit: the clock past the deadline within a unit (too large where it
 *   had a whole call, else stopped for the next call);
 * - exit/Fatal: the reading writes nothing, so an end of the process leaves
 *   nothing behind here; what the series of the environment does after
 *   such an end is the integration case SyncManifestTest (a process of its
 *   own); «halb geschrieben» does not apply: the manifest writes nothing.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Env;
use AB_MCP_Sync_File_Manifest;
use AB_MCP_Sync_File_Manifest_Calls;
use AB_MCP_Test_Seam;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-env.php';
require_once __DIR__ . '/integration/support/class-test-abort.php';
require_once __DIR__ . '/integration/support/class-test-seam.php';

final class SyncFileManifestTest extends TestCase {

	/** @var string A folder of this test. */
	private $dir = '';

	protected function setUp(): void {
		ab_test_reset();
		$this->dir = sys_get_temp_dir() . '/ab-manifest-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Env::$clock = null;
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			self::remove( $this->dir );
		}
	}

	private static function remove( string $path ): void {
		if ( 0 !== strpos( $path, sys_get_temp_dir() . '/ab-manifest-' ) ) {
			throw new RuntimeException( "Will not remove $path." );
		}
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			unlink( $path );
			return;
		}
		chmod( $path, 0755 );
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove( $path . '/' . $entry );
		}
		rmdir( $path );
	}

	private function file( string $rel, string $bytes, int $mtime = 1700000000 ): void {
		$path = $this->dir . '/' . $rel;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		file_put_contents( $path, $bytes );
		touch( $path, $mtime );
	}

	/** A plugin of three files under plugins/shop. */
	private function shop(): void {
		$this->file( 'plugins/shop/shop.php', "<?php\n/* Plugin Name: Shop */" );
		$this->file( 'plugins/shop/inc/a.php', '<?php // a' );
		$this->file( 'plugins/shop/readme.txt', 'Shop' );
	}

	private function read( string $name = 'shop', string $side = 'live', float $deadline = INF, bool $first = true ): array {
		return AB_MCP_Sync_File_Manifest::unit( $this->dir . '/plugins', $name, $side, $deadline, $first, 'plugin' );
	}

	private function seam( array $faults ): \AB_MCP_Test_Seam_Proxy {
		return AB_MCP_Test_Seam::install( AB_MCP_Sync_File_Manifest::class, 'naht_sync_file_manifest', AB_MCP_Sync_File_Manifest_Calls::class, $faults );
	}

	/* ------------------------------------------------------------- Z1 */

	public function testTheHashIsTheSha256OfTheManifestOfEveryFileInTheFormatAbFiles1(): void {
		$this->shop();
		$read = $this->read();
		self::assertSame( array( 'folder', true, null, null, 3 ), array( $read['form'], $read['complete'], $read['cause'], $read['lock'], $read['files'] ) );
		$a    = '<?php // a';
		$shop = "<?php\n/* Plugin Name: Shop */";
		$text = '{"files":[["inc/a.php",' . strlen( $a ) . ',"' . hash( 'sha256', $a ) . '"],["readme.txt",4,"' . hash( 'sha256', 'Shop' ) . '"],["shop.php",' . strlen( $shop ) . ',"' . hash( 'sha256', $shop ) . '"]],"format":"ab-files/1"}';
		self::assertSame( $text, AB_MCP_Sync_File_Manifest::text( $read['entries'] ), 'Path, size, SHA-256, by strcmp(), with the format, no mtime.' );
		self::assertSame( hash( 'sha256', $text ), $read['hash'] );
		self::assertSame( array( 'inc/a.php', 'readme.txt', 'shop.php' ), array_column( $read['entries'], 0 ) );
		self::assertSame( strlen( $a ) + 4 + strlen( $shop ), $read['bytes'] );
		self::assertSame( array( 1700000000, 1700000000, 1700000000 ), array_column( $read['entries'], 3 ), 'mtime beside it.' );
	}

	public function testTheOrderIsStrcmpOfTheWholePathNotTheOrderOfTheWalk(): void {
		$this->file( 'plugins/o/a/b.php', 'x' );
		$this->file( 'plugins/o/a-b.php', 'y' );
		self::assertSame( array( 'a-b.php', 'a/b.php' ), array_column( $this->read( 'o' )['entries'], 0 ), '«-» (0x2D) before «/» (0x2F).' );
	}

	public function testMtimeNeverCountsForEquality(): void {
		$this->shop();
		$before = $this->read()['hash'];
		touch( $this->dir . '/plugins/shop/readme.txt', 1800000000 );
		$after = $this->read();
		self::assertSame( $before, $after['hash'], 'Only the time changed: the same unit (Z1).' );
		self::assertSame( 1800000000, $after['entries'][1][3] );
	}

	public function testEveryFileCountsOneMoreLessOtherBytesOrAnotherNameIsAnotherHash(): void {
		$this->shop();
		$base  = $this->read()['hash'];
		$seen  = array( $base );
		$cases = array(
			'a log written into the folder (no name left out)' => fn() => $this->file( 'plugins/shop/logs/debug.log', 'x' ),
			'a file less'                                     => fn() => unlink( $this->dir . '/plugins/shop/logs/debug.log' ) && rmdir( $this->dir . '/plugins/shop/logs' ) && unlink( $this->dir . '/plugins/shop/readme.txt' ),
			'other bytes of the same size'                     => fn() => $this->file( 'plugins/shop/inc/a.php', '<?php // b' ),
			'another name'                                     => fn() => rename( $this->dir . '/plugins/shop/inc/a.php', $this->dir . '/plugins/shop/inc/b.php' ),
			'an empty folder is no file'                       => fn() => mkdir( $this->dir . '/plugins/shop/empty' ),
		);
		foreach ( $cases as $case => $change ) {
			$change();
			$hash = $this->read()['hash'];
			if ( 'an empty folder is no file' === $case ) {
				self::assertSame( end( $seen ), $hash, $case );
				continue;
			}
			self::assertNotContains( $hash, $seen, $case );
			$seen[] = $hash;
		}
	}

	public function testTheSameFilesInAnotherPlaceGiveTheSameHash(): void {
		$this->shop();
		$here = $this->read()['hash'];
		$this->file( 'other/plugins/shop/shop.php', "<?php\n/* Plugin Name: Shop */", 1600000000 );
		$this->file( 'other/plugins/shop/inc/a.php', '<?php // a' );
		$this->file( 'other/plugins/shop/readme.txt', 'Shop' );
		$there = AB_MCP_Sync_File_Manifest::unit( $this->dir . '/other/plugins', 'shop', 'staging', INF, true, 'plugin' );
		self::assertSame( $here, $there['hash'], 'Paths relative to the unit: the place of the site plays no part (F41).' );
	}

	public function testAUnitOfOneFileHasItsOwnNameAsItsPath(): void {
		$this->file( 'plugins/hello.php', "<?php\n/* Plugin Name: Hello */" );
		$read = $this->read( 'hello.php' );
		self::assertSame( array( 'file', true, 1 ), array( $read['form'], $read['complete'], $read['files'] ) );
		self::assertSame( 'hello.php', $read['entries'][0][0] );
		self::assertSame( hash( 'sha256', AB_MCP_Sync_File_Manifest::text( $read['entries'] ) ), $read['hash'] );
	}

	public function testThePathsOfTwoManifestsDifferOnlyByContentsNeverByMtime(): void {
		$a = str_repeat( 'a', 64 );
		$b = str_repeat( 'b', 64 );
		$paths = AB_MCP_Sync_File_Manifest::paths(
			array( array( 'new.php', 1, $a, 1 ), array( 'same.php', 1, $a, 1 ), array( 'z.php', 1, $a, 1 ), array( 'size.php', 1, $a, 1 ) ),
			array( array( 'data/export.csv', 1, $a, 1 ), array( 'same.php', 1, $a, 9 ), array( 'z.php', 1, $b, 1 ), array( 'size.php', 2, $a, 1 ) )
		);
		self::assertSame(
			array(
				'only_staging' => array( 'new.php' ),
				'only_live'    => array( 'data/export.csv' ),
				'differs'      => array( 'size.php', 'z.php' ),
			),
			$paths
		);
	}

	/* ------------------------------------------------------------- Z2 */

	public function testAFolderOfVersionControlAnywhereLocksTheUnitWithoutAHash(): void {
		foreach ( AB_MCP_Sync_File_Manifest::VCS as $vcs ) {
			$this->shop();
			$this->file( 'plugins/shop/inc/' . $vcs . '/HEAD', 'ref' );
			foreach ( array( 'live' => 'code:live_extra', 'staging' => 'code:link' ) as $side => $lock ) {
				$read = $this->read( 'shop', $side );
				self::assertSame( array( $lock, 'vcs', null, true ), array( $read['lock'], $read['cause'], $read['hash'], $read['complete'] ), "$vcs $side" );
				self::assertSame( array(), $read['entries'] );
			}
			self::remove( $this->dir . '/plugins/shop' );
		}
	}

	public function testTheFolderOfTheUnitItselfOfVersionControlLocksIt(): void {
		// A folder .git, .svn or .hg at the top of the must-use folder is a
		// unit of its own: no hash, the lock of its side (Codex round 1, A1).
		foreach ( AB_MCP_Sync_File_Manifest::VCS as $vcs ) {
			$this->file( 'plugins/' . $vcs . '/HEAD', 'ref' );
			foreach ( array( 'live' => 'code:live_extra', 'staging' => 'code:link' ) as $side => $lock ) {
				$read = AB_MCP_Sync_File_Manifest::unit( $this->dir . '/plugins', $vcs, $side, INF, true, 'mu_plugin' );
				self::assertSame( array( 'folder', $lock, 'vcs', null, true, array() ), array( $read['form'], $read['lock'], $read['cause'], $read['hash'], $read['complete'], $read['entries'] ), "$vcs $side" );
			}
			$nested = AB_MCP_Sync_File_Manifest::unit( $this->dir, 'plugins/' . $vcs, 'live', INF, true, 'upload' );
			self::assertSame( array( 'code:live_extra', 'vcs', null ), array( $nested['lock'], $nested['cause'], $nested['hash'] ), "$vcs below a root of files" );
			self::remove( $this->dir . '/plugins/' . $vcs );
		}
		$this->file( 'plugins/.github/x.yml', 'on: push' );
		self::assertNotNull( AB_MCP_Sync_File_Manifest::unit( $this->dir . '/plugins', '.github', 'live', INF, true, 'mu_plugin' )['hash'], 'Only those three names.' );
	}

	public function testAThemeIsReadOnlyFromTheFolderOfTheThemes(): void {
		$this->file( 'themes/eins/style.css', '/* Theme Name: Eins */' );
		$this->file( 'more/zwei/style.css', '/* Theme Name: Zwei */' );
		$seam = $this->seam( array( array( 'method' => 'theme_root', 'kind' => 'return', 'value' => $this->dir . '/themes/' ) ) );
		$home = AB_MCP_Sync_File_Manifest::unit( $this->dir . '/themes', 'eins', 'live', INF, true, 'theme' );
		self::assertSame( array( 'folder', true, null, 1 ), array( $home['form'], $home['complete'], $home['lock'], $home['files'] ), 'The folder of the themes, also spelled with a slash.' );
		self::assertNotNull( $home['hash'] );
		$calls = count( $seam->calls );
		foreach ( array( 'live', 'staging' ) as $side ) {
			$other = AB_MCP_Sync_File_Manifest::unit( $this->dir . '/more', 'zwei', $side, INF, true, 'theme' );
			self::assertSame( array( 'other', true, 'code:theme_root', 'theme_root', null, 0, array() ), array( $other['form'], $other['complete'], $other['lock'], $other['cause'], $other['hash'], $other['files'], $other['entries'] ), "A further root of themes: locked on either side ($side)." );
		}
		self::assertSame( array( 'theme_root' ), array_values( array_unique( array_column( array_slice( $seam->calls, $calls ), 'method' ) ) ), 'Not read: no file of it listed or opened.' );
		self::assertNotNull( AB_MCP_Sync_File_Manifest::unit( $this->dir . '/more', 'zwei', 'live', INF, true, 'plugin' )['hash'], 'The lock is that of a theme only.' );
		AB_MCP_Test_Seam::restore_all();
		// Without a folder of the themes (no WP_CONTENT_DIR): every theme
		// locked, none read.
		$this->seam( array( array( 'method' => 'theme_root', 'kind' => 'return', 'value' => null ) ) );
		self::assertNull( AB_MCP_Sync_File_Manifest::theme_root() );
		self::assertSame( 'code:theme_root', AB_MCP_Sync_File_Manifest::unit( $this->dir . '/themes', 'eins', 'live', INF, true, 'theme' )['lock'] );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( rtrim( (string) WP_CONTENT_DIR, '/' ) . '/themes', AB_MCP_Sync_File_Manifest::theme_root(), 'The real calls: the folder of the themes of WP_CONTENT_DIR, no filter.' );
		$this->seam( array( array( 'method' => 'theme_root', 'kind' => 'throw', 'message' => 'gone' ) ) );
		$read = AB_MCP_Sync_File_Manifest::unit( $this->dir . '/themes', 'eins', 'live', INF, true, 'theme' );
		self::assertSame( array( false, 'unreadable', null, null ), array( $read['complete'], $read['cause'], $read['lock'], $read['hash'] ), 'A Throwable of theme_root(): not read, no hash.' );
	}

	public function testALinkInTheUnitOrTheUnitAsALinkLocksIt(): void {
		$this->shop();
		symlink( $this->dir . '/plugins/shop/readme.txt', $this->dir . '/plugins/shop/link.txt' );
		$read = $this->read();
		self::assertSame( array( 'code:live_extra', 'link', null ), array( $read['lock'], $read['cause'], $read['hash'] ) );
		unlink( $this->dir . '/plugins/shop/link.txt' );
		symlink( $this->dir . '/plugins/shop', $this->dir . '/plugins/linked' );
		$read = $this->read( 'linked', 'staging' );
		self::assertSame( array( 'other', 'code:link', 'link', null ), array( $read['form'], $read['lock'], $read['cause'], $read['hash'] ), 'The unit itself a link: not followed.' );
		symlink( $this->dir . '/plugins/shop', $this->dir . '/plugins/shop/inc/up' );
		self::assertSame( 'code:live_extra', $this->read()['lock'], 'A link to a folder.' );
	}

	public function testSomethingThatIsNoRegularFileLocksIt(): void {
		if ( ! function_exists( 'posix_mkfifo' ) ) {
			self::markTestSkipped( 'posix_mkfifo() is not there.' );
		}
		$this->shop();
		posix_mkfifo( $this->dir . '/plugins/shop/pipe', 0600 );
		$read = $this->read();
		self::assertSame( array( 'code:live_extra', 'nonfile', null ), array( $read['lock'], $read['cause'], $read['hash'] ) );
	}

	/* ------------------------------------------- faults through the seam */

	/** Each call a fault may hit, with the kind «return»: no hash, why. */
	public function testAReadingThatFailsGivesNoHash(): void {
		$this->shop();
		$cases = array(
			'names'  => array( 'value' => false ),
			'lstat'  => array( 'nth' => 2, 'value' => false ),
			'open'   => array( 'value' => false ),
			'fstat'  => array( 'nth' => 1, 'value' => false ),
			'read'   => array( 'nth' => 1, 'value' => false ),
		);
		foreach ( $cases as $method => $fault ) {
			$this->seam( array( array( 'method' => $method, 'kind' => 'return' ) + $fault ) );
			$read = $this->read();
			self::assertSame( array( false, 'unreadable', null, array() ), array( $read['complete'], $read['cause'], $read['hash'], $read['entries'] ), $method );
			AB_MCP_Test_Seam::restore_all();
		}
		$this->seam( array( array( 'method' => 'fstat', 'nth' => 2, 'kind' => 'return', 'value' => false ) ) );
		self::assertSame( 'unreadable', $this->read()['cause'], 'fstat() after the reading.' );
	}

	public function testAThrowableOfACallGivesNoHash(): void {
		$this->shop();
		foreach ( array( 'names', 'lstat', 'open', 'fstat', 'read' ) as $method ) {
			$this->seam( array( array( 'method' => $method, 'kind' => 'throw', 'message' => 'disk' ) ) );
			$read = $this->read();
			self::assertSame( array( false, 'unreadable', null ), array( $read['complete'], $read['cause'], $read['hash'] ), $method );
			AB_MCP_Test_Seam::restore_all();
		}
	}

	public function testAReadingCutShortGivesNoHash(): void {
		$this->shop();
		$this->seam( array( array( 'method' => 'read', 'nth' => 1, 'kind' => 'half', 'keep' => 2 ) ) );
		$read = $this->read();
		self::assertSame( array( false, 'changed', null ), array( $read['complete'], $read['cause'], $read['hash'] ), 'Fewer bytes than the file has: halb gelesen.' );
	}

	public function testAWriterWhileTheFileIsReadGivesNoHash(): void {
		$this->shop();
		$path = $this->dir . '/plugins/shop/inc/a.php';
		// Between lstat() and open(): another file at the path.
		$this->seam(
			array(
				array(
					'method' => 'open',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => static function () use ( $path ) {
						unlink( $path );
						file_put_contents( $path, 'other' );
					},
				),
			)
		);
		$read = $this->read();
		self::assertSame( array( false, 'changed', null ), array( $read['complete'], $read['cause'], $read['hash'] ), 'Not the file lstat() saw (whichever inode the file system gives the new one).' );
		AB_MCP_Test_Seam::restore_all();
		// Between lstat() and open(): written anew in place, the same inode.
		$this->file( 'plugins/shop/inc/a.php', '<?php // a' );
		$this->seam(
			array(
				array(
					'method' => 'open',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => static function () use ( $path ) {
						file_put_contents( $path, '<?php // b' );
					},
				),
			)
		);
		self::assertSame( 'changed', $this->read()['cause'], 'Written anew between lstat() and open(): its time is no longer the one lstat() saw.' );
		AB_MCP_Test_Seam::restore_all();
		// After it was opened and read: another file renamed over the path
		// (its old inode still open), the same size and time (GitHub-Codex, P2).
		$this->file( 'plugins/shop/inc/a.php', '<?php // a' );
		$this->seam(
			array(
				array(
					'method' => 'fstat',
					'nth'    => 2,
					'kind'   => 'before',
					'before' => static function () use ( $path ) {
						file_put_contents( $path . '.neu', '<?php // b' );
						touch( $path . '.neu', 1700000000 );
						rename( $path . '.neu', $path );
					},
				),
			)
		);
		$read = $this->read();
		self::assertSame( array( false, 'changed', null ), array( $read['complete'], $read['cause'], $read['hash'] ), 'Renamed over the path while it was read: the path names another file.' );
		AB_MCP_Test_Seam::restore_all();
		$this->file( 'plugins/shop/inc/a.php', '<?php // a' );
		// While it is read: it grows.
		$this->seam(
			array(
				array(
					'method' => 'fstat',
					'nth'    => 2,
					'kind'   => 'before',
					'before' => static function () use ( $path ) {
						file_put_contents( $path, 'more', FILE_APPEND );
					},
				),
			)
		);
		$read = $this->read();
		self::assertSame( array( false, 'changed', null ), array( $read['complete'], $read['cause'], $read['hash'] ), 'Its size changed while it was read.' );
		AB_MCP_Test_Seam::restore_all();
		// While it is read: rewritten with the same size and another time.
		$this->seam(
			array(
				array(
					'method' => 'fstat',
					'nth'    => 2,
					'kind'   => 'before',
					'before' => static function () use ( $path ) {
						touch( $path, 1900000000 );
					},
				),
			)
		);
		self::assertSame( 'changed', $this->read()['cause'], 'Its time changed while it was read.' );
	}

	/**
	 * A stand-in of the calls whose lstat() of one path (its nth call) or
	 * fstat() (its nth call) says one field otherwise, the rest real.
	 */
	private static function told( string $method, string $path, int $nth, string $field ): object {
		return new class( $method, $path, $nth, $field ) {
			private $method;
			private $path;
			private $nth;
			private $field;
			private $seen = 0;
			private $real;
			public function __construct( $method, $path, $nth, $field ) {
				$this->method = $method;
				$this->path   = $path;
				$this->nth    = $nth;
				$this->field  = $field;
				$this->real   = new AB_MCP_Sync_File_Manifest_Calls();
			}
			private function other( $stat ) {
				if ( is_array( $stat ) && ++$this->seen === $this->nth ) {
					if ( '*' === $this->field ) {
						return false;
					}
					$stat[ $this->field ] = (int) $stat[ $this->field ] + 1;
				}
				return $stat;
			}
			public function names( $dir ) {
				return $this->real->names( $dir );
			}
			public function lstat( $path ) {
				$stat = $this->real->lstat( $path );
				return 'lstat' === $this->method && $path === $this->path ? $this->other( $stat ) : $stat;
			}
			public function open( $path ) {
				return $this->real->open( $path );
			}
			public function fstat( $handle ) {
				$stat = $this->real->fstat( $handle );
				return 'fstat' === $this->method ? $this->other( $stat ) : $stat;
			}
			public function read( $handle, $length ) {
				return $this->real->read( $handle, $length );
			}
			public function theme_root() {
				return $this->real->theme_root();
			}
			public function limit() {
				return $this->real->limit();
			}
			public function close( $handle ) {
				$this->real->close( $handle );
			}
		};
	}

	public function testEachFieldOfTheIdentityBeforeWhileAndAfterTheReadingCounts(): void {
		// One contract on every file system (Behebung 2): device, inode, size,
		// mtime and ctime the same in lstat() before, fstat() before and after
		// and lstat() after the reading; one field otherwise is no hash.
		$this->shop();
		$path  = $this->dir . '/plugins/shop/inc/a.php';
		$whole = $this->read()['hash'];
		foreach ( array( 'dev', 'ino', 'size', 'mtime', 'ctime' ) as $field ) {
			$moments = array(
				'lstat() before the opening' => array( 'lstat', 1 ),
				'fstat() before the reading' => array( 'fstat', 1 ),
				'fstat() after the reading'  => array( 'fstat', 2 ),
				'lstat() once the unit is read' => array( 'lstat', 2 ),
			);
			foreach ( $moments as $moment => list( $method, $nth ) ) {
				AB_MCP_Test_Seam::install( AB_MCP_Sync_File_Manifest::class, 'naht_sync_file_manifest', self::told( $method, $path, $nth, $field ) );
				$read = $this->read();
				AB_MCP_Test_Seam::restore_all();
				self::assertSame( array( false, 'changed', null ), array( $read['complete'], $read['cause'], $read['hash'] ), "$field otherwise in $moment." );
			}
		}
		self::assertSame( $whole, $this->read()['hash'], 'Read as it is: the same hash.' );
		// fstat() after the reading fails: no hash, not whole, unreadable
		// (not «changed»), and the path is not asked again (Behebung 3: the
		// clause of its own, z1-fstat-after).
		$seam = $this->seam( array( array( 'method' => 'fstat', 'nth' => 2, 'kind' => 'return', 'value' => false ) ) );
		$read = $this->read();
		self::assertSame( array( false, 'unreadable', null, array() ), array( $read['complete'], $read['cause'], $read['hash'], $read['entries'] ), 'fstat() after the reading gave no answer.' );
		self::assertSame( array( 'lstat', 'lstat', 'lstat', 'open', 'fstat', 'read', 'read', 'fstat', 'close' ), array_values( array_filter( array_column( $seam->calls, 'method' ), static fn( $m ) => 'names' !== $m && 'limit' !== $m ) ), 'Ended at that call: no lstat() of the path after it.' );
		AB_MCP_Test_Seam::restore_all();
		// The path gone once the unit is read: changed, never hashed.
		AB_MCP_Test_Seam::install( AB_MCP_Sync_File_Manifest::class, 'naht_sync_file_manifest', self::told( 'lstat', $path, 2, '*' ) );
		self::assertSame( 'changed', $this->read()['cause'], 'The path names no file once the unit is read.' );
		AB_MCP_Test_Seam::restore_all();
	}

	public function testATimeLimitWithinAUnitStopsItOrMakesItTooLarge(): void {
		$this->shop();
		$t                      = 100.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
			$t += 1.0;
			return $t;
		};
		$read = $this->read( 'shop', 'live', 102.5, true );
		self::assertSame( array( false, 'too_large', null, false ), array( $read['complete'], $read['cause'], $read['hash'], $read['stopped'] ), 'It had a whole call: too large (F38).' );
		$t    = 100.0;
		$read = $this->read( 'shop', 'live', 102.5, false );
		self::assertSame( array( true, null, array() ), array( $read['stopped'], $read['hash'], $read['entries'] ), 'Begun late in a call: stopped, read anew by the next.' );
		$t    = 100.0;
		$read = $this->read( 'shop', 'live', 1000.0, false );
		self::assertTrue( $read['complete'], 'In time: read whole.' );
	}

	public function testTheTimeOfTheCallIsAskedWithinALargeFileToo(): void {
		$this->file( 'plugins/big/big.bin', str_repeat( 'x', 3 * AB_MCP_Sync_File_Manifest::CHUNK ) );
		$t                      = 100.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
			$t += 1.0;
			return $t;
		};
		// Before the entry and before the file the clock says 101 and 102;
		// after the first part 103, after the second 104.
		$seam = $this->seam( array() );
		$read = $this->read( 'big', 'live', 103.5, true );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array( false, 'too_large' ), array( $read['complete'], $read['cause'] ), 'Within the file, not only after it.' );
		self::assertCount( 2, array_filter( $seam->calls, static fn( $c ) => 'read' === $c['method'] ), 'Two parts read, then the time: not the third.' );
		// A file of one part: the clock before it is opened and in the final
		// check, not after its last part as well (Behebung 5).
		$this->file( 'plugins/klein.php', '<?php // klein' );
		$t = 100.0;
		self::assertTrue( $this->read( 'klein.php', 'live', 103.5, true )['complete'], 'Three times asked (before it, the final check, the release: 101, 102, 103), in time.' );
	}

	public function testMoreFilesThanAManifestHoldsGiveNoHash(): void {
		$this->shop();
		self::assertSame( 100000, AB_MCP_Sync_File_Manifest::MAX_FILES );
		self::assertSame( AB_MCP_Sync_File_Manifest::MAX_FILES, ( new AB_MCP_Sync_File_Manifest_Calls() )->limit() );
		// shop: the folder inc and three files, four entries.
		$this->seam( array( array( 'method' => 'limit', 'kind' => 'return', 'value' => 4 ) ) );
		self::assertTrue( $this->read()['complete'], 'As many files and folders as it holds.' );
		AB_MCP_Test_Seam::restore_all();
		$this->seam( array( array( 'method' => 'limit', 'kind' => 'return', 'value' => 3 ) ) );
		$read = $this->read();
		self::assertSame( array( false, 'too_many', null ), array( $read['complete'], $read['cause'], $read['hash'] ), 'One more: no hash.' );
		AB_MCP_Test_Seam::restore_all();
		// Folders count as well (GitHub-Codex on ed1e512, P2): empty ones only.
		foreach ( array( 'a', 'b', 'c' ) as $empty ) {
			mkdir( $this->dir . '/plugins/leer/' . $empty, 0755, true );
		}
		$this->seam( array( array( 'method' => 'limit', 'kind' => 'return', 'value' => 2 ) ) );
		self::assertSame( array( false, 'too_many' ), array( $this->read( 'leer' )['complete'], $this->read( 'leer' )['cause'] ), 'Three empty folders past a limit of two.' );
	}

	public function testTheTimeOfTheCallIsAskedBeforeEachFolderAndFileAlsoEmptyOnes(): void {
		// GitHub-Codex on ed1e512, P2: an empty folder and a file of 0 bytes,
		// the time of the call over before the unit is read.
		mkdir( $this->dir . '/plugins/leicht/leer', 0755, true );
		$this->file( 'plugins/leicht/null.txt', '' );
		$asked                  = 0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$asked ) {
			++$asked;
			return 200.0;
		};
		$seam = $this->seam( array() );
		$read = $this->read( 'leicht', 'live', 100.0, true );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array( false, 'too_large', null ), array( $read['complete'], $read['cause'], $read['hash'] ), 'Over before it was read: too large where it had a whole call.' );
		self::assertSame( array( 'lstat', 'names' ), array_column( $seam->calls, 'method' ), 'Stopped before the first entry of the folder: none of them asked.' );
		self::assertGreaterThan( 0, $asked, 'The clock was asked.' );
		$read = $this->read( 'leicht', 'live', 100.0, false );
		self::assertSame( array( true, null ), array( $read['stopped'], $read['hash'] ), 'Begun late in a call: stopped.' );
		// A unit of empty folders only.
		foreach ( array( 'a', 'b' ) as $empty ) {
			mkdir( $this->dir . '/plugins/nur-ordner/' . $empty, 0755, true );
		}
		self::assertSame( 'too_large', $this->read( 'nur-ordner', 'live', 100.0, true )['cause'], 'No file, only folders: the clock is asked all the same.' );
		// A unit of one file of 0 bytes.
		$this->file( 'plugins/null.php', '' );
		$seam = $this->seam( array() );
		self::assertSame( 'too_large', $this->read( 'null.php', 'live', 100.0, true )['cause'] );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array( 'lstat' ), array_column( $seam->calls, 'method' ), 'Stopped before the file is opened.' );
		// In time: whole.
		AB_MCP_Sync_Env::$clock = static fn() => 50.0;
		self::assertTrue( $this->read( 'leicht', 'live', 100.0, true )['complete'] );
	}

	public function testTheTimeOfTheCallIsAskedInTheFinalCheckOfTheUnitToo(): void {
		// Behebung 5 (closed Codex confirmation of 216596c): a tree that does
		// not change, on slow storage, past its time while the folders are
		// listed again or the paths asked again: no hash, as while it is walked.
		$this->shop();
		$t                      = 50.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
			return $t;
		};
		$late    = static function () use ( &$t ) {
			$t = 200.0;
		};
		$moments = array(
			'while the folders are listed again' => array( 'method' => 'names', 'nth' => 3 ),
			'while the paths are asked again'    => array( 'method' => 'lstat', 'nth' => 6 ),
		);
		foreach ( $moments as $moment => $fault ) {
			foreach ( array( true, false ) as $first ) {
				$t    = 50.0;
				$seam = $this->seam( array( $fault + array( 'kind' => 'before', 'before' => $late ) ) );
				$read = $this->read( 'shop', 'live', 100.0, $first );
				AB_MCP_Test_Seam::restore_all();
				self::assertCount( $fault['nth'], array_filter( $seam->calls, static fn( $c ) => $fault['method'] === $c['method'] ), "Stopped at the next folder or path $moment: no {$fault['method']}() after the time." );
				$want = $first ? array( false, 'too_large', false, null, array() ) : array( false, null, true, null, array() );
				self::assertSame( $want, array( $read['complete'], $read['cause'], $read['stopped'], $read['hash'], $read['entries'] ), "Past its time $moment (" . ( $first ? 'a whole call: too large' : 'begun late: stopped' ) . ').' );
			}
		}
		$t = 50.0;
		self::assertTrue( $this->read( 'shop', 'live', 100.0, true )['complete'], 'In time: whole.' );
	}

	/** A stand-in of the calls that runs $then right after the nth real call of $method (lstat() of $path where given), the rest real. */
	private static function after( string $method, int $nth, callable $then, ?string $path = null ): object {
		return new class( $method, $nth, $then, $path ) {
			private $method;
			private $nth;
			private $then;
			private $path;
			private $seen = 0;
			private $real;
			public function __construct( $method, $nth, $then, $path ) {
				$this->method = $method;
				$this->nth    = $nth;
				$this->then   = $then;
				$this->path   = $path;
				$this->real   = new AB_MCP_Sync_File_Manifest_Calls();
			}
			private function then( $name, $arg, $out ) {
				if ( $name === $this->method && ( null === $this->path || $arg === $this->path ) && ++$this->seen === $this->nth ) {
					( $this->then )();
				}
				return $out;
			}
			public function names( $dir ) {
				return $this->then( 'names', $dir, $this->real->names( $dir ) );
			}
			public function lstat( $path ) {
				return $this->then( 'lstat', $path, $this->real->lstat( $path ) );
			}
			public function open( $path ) {
				return $this->real->open( $path );
			}
			public function fstat( $handle ) {
				return $this->real->fstat( $handle );
			}
			public function read( $handle, $length ) {
				return $this->real->read( $handle, $length );
			}
			public function theme_root() {
				return $this->real->theme_root();
			}
			public function limit() {
				return $this->real->limit();
			}
			public function close( $handle ) {
				$this->real->close( $handle );
			}
		};
	}

	public function testNoHashOnceTheTimeOfTheCallIsOverAlsoAfterTheLastQueryOfTheFinalCheck(): void {
		// Behebung 6: the time runs out with the last call of still() or right
		// after it, while the manifest is sorted and hashed. The clock is asked
		// once more where the hash is given; no hash, whatever comes last.
		$this->shop();
		mkdir( $this->dir . '/plugins/leer' );
		$t                      = 50.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$t ) {
			return $t;
		};
		$late  = static function () use ( &$t ) {
			$t = 200.0;
		};
		$cases = array(
			// (a) During the last lstat() of still() (shop.php asked again).
			'during the last lstat() of the final check' => array( 'shop', array( 'method' => 'lstat', 'nth' => 8, 'kind' => 'before', 'before' => $late ) ),
			// (b) During the final listing of an empty folder (the unit itself).
			'during the final listing of an empty folder' => array( 'leer', array( 'method' => 'names', 'nth' => 2, 'kind' => 'before', 'before' => $late ) ),
			// (c) After the last call of still(): while it is sorted and hashed.
			'between the hash and its release'            => array( 'shop', 'after' ),
		);
		foreach ( $cases as $case => list( $unit, $fault ) ) {
			foreach ( array( true, false ) as $first ) {
				$t = 50.0;
				if ( 'after' === $fault ) {
					// Right after shop.php is asked again, the last call of still().
					AB_MCP_Test_Seam::install( AB_MCP_Sync_File_Manifest::class, 'naht_sync_file_manifest', self::after( 'lstat', 2, $late, $this->dir . '/plugins/shop/shop.php' ) );
				} else {
					$this->seam( array( $fault ) );
				}
				$read = $this->read( $unit, 'live', 100.0, $first );
				AB_MCP_Test_Seam::restore_all();
				self::assertSame( 200.0, $t, "$case: the time ran out." );
				$want = $first ? array( false, 'too_large', false, null, array() ) : array( false, null, true, null, array() );
				self::assertSame( $want, array( $read['complete'], $read['cause'], $read['stopped'], $read['hash'], $read['entries'] ), "$case (" . ( $first ? 'a whole call: too large' : 'begun late: stopped' ) . '): no hash.' );
			}
		}
		$t = 50.0;
		self::assertNotNull( $this->read( 'leer' )['hash'], 'In time: an empty folder has its hash.' );
	}

	public function testAFolderOrFileAddedRemovedOrReplacedWhileTheUnitIsReadGivesNoHash(): void {
		// GitHub-Codex on ed1e512, P2 (Z1, gleichzeitiger Schreiber): what
		// the listing of a folder did not see, or a file changed after it
		// was hashed, before the unit is read whole.
		$this->shop();
		$plugins = $this->dir . '/plugins/shop';
		$whole   = $this->read()['hash'];
		$cases   = array(
			'a file added to a folder after it was listed'  => static function () use ( $plugins ) {
				file_put_contents( $plugins . '/inc/neu.php', '<?php // neu' );
			},
			'a folder added after it was listed'            => static function () use ( $plugins ) {
				mkdir( $plugins . '/neu' );
			},
			'a file hashed before, removed'                 => static function () use ( $plugins ) {
				unlink( $plugins . '/inc/a.php' );
			},
			'a file hashed before, replaced (same size and time)' => static function () use ( $plugins ) {
				file_put_contents( $plugins . '/inc/a.neu', '<?php // b' );
				touch( $plugins . '/inc/a.neu', 1700000000 );
				rename( $plugins . '/inc/a.neu', $plugins . '/inc/a.php' );
			},
		);
		foreach ( $cases as $case => $writer ) {
			// The writer comes when the last file (shop.php) is opened: inc and
			// its file are read already.
			$this->seam( array( array( 'method' => 'open', 'nth' => 3, 'kind' => 'before', 'before' => $writer ) ) );
			$read = $this->read();
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( array( false, 'changed', null, array() ), array( $read['complete'], $read['cause'], $read['hash'], $read['entries'] ), $case );
			self::remove( $plugins );
			$this->shop();
		}
		self::assertSame( $whole, $this->read()['hash'], 'Read again as it is: whole, the same hash.' );
		// A folder that cannot be listed again, or a call that throws then: unreadable.
		$this->seam( array( array( 'method' => 'names', 'nth' => 3, 'kind' => 'return', 'value' => false ) ) );
		self::assertSame( 'unreadable', $this->read()['cause'] );
		AB_MCP_Test_Seam::restore_all();
		foreach ( array( 'names' => 3, 'lstat' => 6 ) as $method => $nth ) {
			$this->seam( array( array( 'method' => $method, 'nth' => $nth, 'kind' => 'throw', 'message' => 'gone' ) ) );
			$read = $this->read();
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( array( false, 'unreadable', null ), array( $read['complete'], $read['cause'], $read['hash'] ), "$method once the unit is read throws." );
		}
	}

}
