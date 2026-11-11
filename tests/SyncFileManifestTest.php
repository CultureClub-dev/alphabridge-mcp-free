<?php
/**
 * The manifest of a unit (AB_MCP_Sync_File_Manifest, Z8b branch 2; plan
 * 3.1 Z1 and Z2, E1), the one place that gives the hash of a unit:
 *
 * - Z1: equal only by the whole manifest of every file (path, size,
 *   SHA-256, by strcmp(), ab-files/1); mtime never counts; one file more,
 *   less, other bytes, another name: another hash;
 * - Z2: a folder .git, .svn or .hg anywhere, a link (the unit itself
 *   included) or a file that is no regular file locks the unit
 *   (code:live_extra here, code:link on staging) without a hash; no name
 *   is left out within a unit;
 * - the faults of its calls to the outside (plan 8.3, through the seam
 *   naht_sync_file_manifest), each with the promise at its place: a
 *   reading that fails, throws, is cut, sees a writer or runs out of time
 *   gives no hash, with why (never «equal» on a half reading).
 *
 * Kinds of fault and where they apply to reading a unit:
 * - return false (Lesefehler): names(), lstat(), open(), fstat(), read();
 * - Throwable: any call;
 * - half (halb gelesen): read() gives fewer bytes;
 * - before (gleichzeitiger Schreiber): a writer between lstat() and open()
 *   (another file at the path), and between the reads (the size changes);
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
		return AB_MCP_Sync_File_Manifest::unit( $this->dir . '/plugins', $name, $side, $deadline, $first );
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
		$there = AB_MCP_Sync_File_Manifest::unit( $this->dir . '/other/plugins', 'shop', 'staging', INF, true );
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
		self::assertSame( array( false, 'changed', null ), array( $read['complete'], $read['cause'], $read['hash'] ), 'Not the file lstat() saw (inode).' );
		AB_MCP_Test_Seam::restore_all();
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
		$read = $this->read( 'big', 'live', 101.5, true );
		self::assertSame( array( false, 'too_large' ), array( $read['complete'], $read['cause'] ), 'Within the file, not only after it.' );
	}

	public function testMoreFilesThanAManifestHoldsGiveNoHash(): void {
		$this->shop();
		self::assertSame( 100000, AB_MCP_Sync_File_Manifest::MAX_FILES );
		self::assertSame( AB_MCP_Sync_File_Manifest::MAX_FILES, ( new AB_MCP_Sync_File_Manifest_Calls() )->limit() );
		$this->seam( array( array( 'method' => 'limit', 'kind' => 'return', 'value' => 3 ) ) );
		self::assertTrue( $this->read()['complete'], 'As many files as it holds.' );
		AB_MCP_Test_Seam::restore_all();
		$this->seam( array( array( 'method' => 'limit', 'kind' => 'return', 'value' => 2 ) ) );
		$read = $this->read();
		self::assertSame( array( false, 'too_many', null ), array( $read['complete'], $read['cause'], $read['hash'] ), 'One more: no hash.' );
	}
}
