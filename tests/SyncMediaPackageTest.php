<?php
/**
 * Z8 branch 10 (decision 26), what needs no database: the line of a package
 * of media (AB_MCP_Sync_Media_Package::line(), lines_of(), pieces()), what
 * lies at a path a step would lay a file at (AB_MCP_Sync_Media::
 * target_state()), and the pure parts of the write side of attachments
 * (AB_MCP_Sync_Attachment_Writer: the files the run laid, the file of a new
 * attachment with its row). End to end in tests/integration/SyncMediaTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Attachment_Writer;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Media;
use AB_MCP_Sync_Media_Package;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/sync-canon-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-media.php';
require_once __DIR__ . '/../includes/sync/class-sync-import.php';
require_once __DIR__ . '/../includes/sync/class-sync-media-package.php';
require_once __DIR__ . '/../includes/sync/class-sync-attachment-writer.php';

final class SyncMediaPackageTest extends TestCase {

	/** @var string A temporary uploads folder. */
	private $dir = '';

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/ab-media-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/2026/10', 0777, true );
		$this->dir = (string) realpath( $this->dir );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->dir ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $file ) {
				( $file->isDir() && ! $file->isLink() ) ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->dir );
		}
	}

	/** A line as lines_of() writes it, with fields over. */
	private static function line( array $over = array() ): string {
		$bytes = 'abc';
		return AB_MCP_Sync_Canon::encode(
			array_merge(
				array(
					'data'   => base64_encode( $bytes ),
					'kind'   => 'file',
					'path'   => '2026/10/a.jpg',
					'piece'  => 0,
					'pieces' => 1,
					'ref'    => '12',
					'sha256' => hash( 'sha256', $bytes ),
					'size'   => 3,
				),
				$over
			)
		);
	}

	/* --------------------------------------------------------- the line */

	public function testALineIsOnePieceOfOneFileInTheOneSpellingOfTheCanon(): void {
		$read = AB_MCP_Sync_Media_Package::line( self::line() );
		self::assertSame(
			array(
				'ref'    => '12',
				'path'   => '2026/10/a.jpg',
				'piece'  => 0,
				'pieces' => 1,
				'sha256' => hash( 'sha256', 'abc' ),
				'size'   => 3,
				'bytes'  => 'abc',
			),
			$read
		);
		self::assertSame( array( 'data', 'kind', 'path', 'piece', 'pieces', 'ref', 'sha256', 'size' ), AB_MCP_Sync_Media_Package::FIELDS );
		self::assertSame( 1, AB_MCP_Sync_Media_Package::pieces( 0 ), 'An empty file is one piece.' );
		self::assertSame( 1, AB_MCP_Sync_Media_Package::pieces( AB_MCP_Sync_Media_Package::PIECE ) );
		self::assertSame( 2, AB_MCP_Sync_Media_Package::pieces( AB_MCP_Sync_Media_Package::PIECE + 1 ) );
		self::assertNotNull( AB_MCP_Sync_Media_Package::line( self::line( array( 'data' => '', 'sha256' => hash( 'sha256', '' ), 'size' => 0 ) ) ), 'An empty file.' );
	}

	/** @return array<string,array{0:string}> */
	public static function bad_lines(): array {
		$big = AB_MCP_Sync_Media_Package::PIECE + 2;
		return array(
			'another spelling'       => array( str_replace( '"kind":"file"', '"kind": "file"', self::line() ) ),
			'a field too many'       => array( self::line( array( 'roles' => 'file' ) ) ),
			'another kind'           => array( self::line( array( 'kind' => 'post' ) ) ),
			'a ref that is no id'    => array( self::line( array( 'ref' => '0' ) ) ),
			'a ref that is a number' => array( self::line( array( 'ref' => 12 ) ) ),
			'a path up'              => array( self::line( array( 'path' => '2026/../wp-config.php' ) ) ),
			'an absolute path'       => array( self::line( array( 'path' => '/etc/passwd' ) ) ),
			'a backslash'            => array( self::line( array( 'path' => '2026\\a.jpg' ) ) ),
			'a NUL'                  => array( self::line( array( 'path' => "a\0.jpg" ) ) ),
			'a hash in capitals'     => array( self::line( array( 'sha256' => strtoupper( hash( 'sha256', 'abc' ) ) ) ) ),
			'a size below zero'      => array( self::line( array( 'size' => -1 ) ) ),
			'more pieces than its size gives' => array( self::line( array( 'pieces' => 2 ) ) ),
			'a piece too many, its first whole' => array( self::line( array( 'size' => $big, 'pieces' => 3, 'sha256' => str_repeat( 'a', 64 ), 'data' => base64_encode( str_repeat( 'a', AB_MCP_Sync_Media_Package::PIECE ) ) ) ) ),
			'a piece past its end'   => array( self::line( array( 'piece' => 1 ) ) ),
			'data that is no base64' => array( self::line( array( 'data' => 'a b' ) ) ),
			'its bytes in base64 spelt otherwise' => array( self::line( array( 'data' => 'YW Jj' ) ) ),
			'data of another size'   => array( self::line( array( 'data' => base64_encode( 'abcd' ) ) ) ),
			'a short first piece'    => array( self::line( array( 'size' => $big, 'pieces' => 2, 'sha256' => str_repeat( 'a', 64 ) ) ) ),
		);
	}

	#[DataProvider( 'bad_lines' )]
	public function testALineThatIsNotOneAsTheStagingSiteWritesItIsNone( string $line ): void {
		self::assertNull( AB_MCP_Sync_Media_Package::line( $line ) );
	}

	public function testTheLinesOfAFileAreItsPiecesInTheirOrderReadAgainstItsList(): void {
		$bytes = random_bytes( 1000 );
		file_put_contents( $this->dir . '/2026/10/a.jpg', $bytes );
		$file  = array(
			'path'   => '2026/10/a.jpg',
			'size'   => 1000,
			'sha256' => hash( 'sha256', $bytes ),
		);
		$lines = array();
		self::assertTrue(
			AB_MCP_Sync_Media_Package::lines_of(
				$this->dir,
				'12',
				$file,
				static function ( $line ) use ( &$lines ) {
					$lines[] = $line;
					return true;
				}
			)
		);
		self::assertCount( 1, $lines );
		$read = AB_MCP_Sync_Media_Package::line( $lines[0] );
		self::assertSame( $bytes, $read['bytes'] );
		// A file that changed since its list was read: no line of it counts.
		foreach ( array(
			'other bytes'  => array( 'sha256' => hash( 'sha256', 'x' ) ),
			'other size'   => array( 'size' => 999 ),
			'a path up'    => array( 'path' => '../a.jpg' ),
			'gone'         => array( 'path' => '2026/10/b.jpg' ),
		) as $what => $over ) {
			$answer = AB_MCP_Sync_Media_Package::lines_of( $this->dir, '12', $over + $file, static fn() => true );
			self::assertInstanceOf( \WP_Error::class, $answer, $what );
			self::assertSame( 'ab_mcp_sync_media_changed', $answer->get_error_code(), $what );
		}
		// A link at the path is never read.
		file_put_contents( $this->dir . '/geheim.txt', $bytes );
		symlink( $this->dir . '/geheim.txt', $this->dir . '/2026/10/link.jpg' );
		self::assertInstanceOf( \WP_Error::class, AB_MCP_Sync_Media_Package::lines_of( $this->dir, '12', array( 'path' => '2026/10/link.jpg' ) + $file, static fn() => true ) );
		// What $put refuses ends the lines.
		$refused = AB_MCP_Sync_Media_Package::lines_of( $this->dir, '12', $file, static fn() => new \WP_Error( 'full', 'x' ) );
		self::assertSame( 'full', $refused->get_error_code() );
	}

	public function testALargeFileIsSplitIntoPiecesOfThePieceSize(): void {
		$size  = AB_MCP_Sync_Media_Package::PIECE + 10;
		$bytes = str_repeat( 'z', $size );
		file_put_contents( $this->dir . '/2026/10/big.bin', $bytes );
		$lines = array();
		AB_MCP_Sync_Media_Package::lines_of(
			$this->dir,
			'7',
			array(
				'path'   => '2026/10/big.bin',
				'size'   => $size,
				'sha256' => hash( 'sha256', $bytes ),
			),
			static function ( $line ) use ( &$lines ) {
				$lines[] = AB_MCP_Sync_Media_Package::line( $line );
				return true;
			}
		);
		self::assertSame( array( 0, 1 ), array_column( $lines, 'piece' ) );
		self::assertSame( array( AB_MCP_Sync_Media_Package::PIECE, 10 ), array_map( static fn( $l ) => strlen( $l['bytes'] ), $lines ) );
		self::assertSame( $bytes, $lines[0]['bytes'] . $lines[1]['bytes'] );
		self::assertLessThan( 5177344, strlen( (string) AB_MCP_Sync_Canon::encode( array( 'data' => base64_encode( $lines[0]['bytes'] ) ) ) ) + 300, 'A line of a full piece stays within the lines of a chunk.' );
		// The gzip of the base64 of bytes that do not compress (random, as a
		// JPEG nearly is) takes about as much as the bytes: a full package of
		// media stays within the 64 MiB of a package in Free, its lines well
		// within the 256 MiB unpacked.
		$raw   = random_bytes( 1048576 );
		$ratio = strlen( (string) gzencode( base64_encode( $raw ), 6 ) ) / strlen( $raw );
		self::assertLessThan( 67108864, (int) ceil( AB_MCP_Sync_Media_Package::MAX_BYTES * $ratio ) + 1048576, 'The chunks of a full package of media (ratio ' . $ratio . ').' );
		self::assertLessThan( 268435456, (int) ceil( AB_MCP_Sync_Media_Package::MAX_BYTES * 4 / 3 ) + 1048576, 'Its lines once unpacked.' );
	}

	/* ------------------------------------------------- what lies at a path */

	public function testWhatLiesAtThePathOfAFileToLay(): void {
		file_put_contents( $this->dir . '/2026/10/da.jpg', 'x' );
		mkdir( $this->dir . '/2026/10/ordner.jpg' );
		mkdir( $this->dir . '/anderswo' );
		symlink( $this->dir . '/anderswo', $this->dir . '/2026/09' );
		symlink( $this->dir . '/2026/10/da.jpg', $this->dir . '/2026/10/link.jpg' );
		file_put_contents( $this->dir . '/datei', 'x' );
		self::assertSame( 'absent', AB_MCP_Sync_Media::target_state( $this->dir, '2026/10/neu.jpg' ) );
		self::assertSame( 'absent', AB_MCP_Sync_Media::target_state( $this->dir, '2027/01/neu.jpg' ), 'Folders to be made.' );
		self::assertSame( 'file', AB_MCP_Sync_Media::target_state( $this->dir, '2026/10/da.jpg' ) );
		self::assertSame( 'other', AB_MCP_Sync_Media::target_state( $this->dir, '2026/10/ordner.jpg' ), 'A folder at the path.' );
		self::assertSame( 'other', AB_MCP_Sync_Media::target_state( $this->dir, '2026/10/link.jpg' ), 'A link at the path.' );
		symlink( $this->dir . '/nirgends', $this->dir . '/2026/10/tot.jpg' );
		self::assertSame( 'other', AB_MCP_Sync_Media::target_state( $this->dir, '2026/10/tot.jpg' ), 'A link to nothing at the path: no path to lay at.' );
		self::assertSame( 'unsafe', AB_MCP_Sync_Media::target_state( $this->dir, '2026/09/neu.jpg' ), 'A link on the way.' );
		self::assertSame( 'unsafe', AB_MCP_Sync_Media::target_state( $this->dir, 'datei/neu.jpg' ), 'A file on the way.' );
		self::assertSame( 'unsafe', AB_MCP_Sync_Media::target_state( $this->dir, '../neu.jpg' ) );
		self::assertSame( 'absent', AB_MCP_Sync_Media::target_state( $this->dir . '/nicht-da', 'a.jpg' ), 'An uploads folder still to be made.' );
	}

	/* ------------------------------------------------------ the writer */

	public function testTheFilesTheRunLaidAreItsOwnAndAFileThereAlreadyIsNot(): void {
		$journal = array(
			'files' => array(
				array( 'path' => 'a.jpg', 'sha256' => 'h1', 'size' => 1, 'state' => 'present', 'own' => false ),
				array( 'path' => 'b.jpg', 'sha256' => 'h2', 'size' => 1, 'state' => 'laid', 'own' => true ),
				array( 'path' => 'c.jpg', 'sha256' => 'h3', 'size' => 1, 'state' => 'creating', 'own' => false ),
				array( 'path' => 'd.jpg', 'sha256' => 'h4', 'size' => 1, 'state' => 'failed', 'own' => false ),
				array( 'path' => 'e.jpg', 'sha256' => 'h5', 'size' => 1, 'state' => 'partial', 'own' => true ),
				array( 'path' => 'f.jpg', 'sha256' => 'h6', 'size' => 1, 'state' => 'planned', 'own' => false ),
			),
		);
		self::assertSame( array( 'b.jpg', 'e.jpg' ), array_column( AB_MCP_Sync_Attachment_Writer::own_files( $journal ), 'path' ), 'Laid by the run, shown at its path: own; being made when its request ended: never own (Codex round 1, A2).' );
		self::assertSame( array( array( 'path' => 'c.jpg', 'state' => 'unclear' ) ), array_map( static fn( $f ) => array( 'path' => $f['path'], 'state' => $f['state'] ), AB_MCP_Sync_Attachment_Writer::unclear_files( $journal ) ), 'Named as unclear, with its path.' );
		$journal['files'][] = array( 'path' => 'g.jpg', 'sha256' => 'h7', 'size' => 1, 'state' => 'unclear', 'own' => true, 'note' => 'x' );
		self::assertNotContains( 'g.jpg', array_column( AB_MCP_Sync_Attachment_Writer::own_files( $journal ), 'path' ), 'A file not shown at its path is never own, whatever its mark.' );
		self::assertSame( array(), AB_MCP_Sync_Attachment_Writer::own_files( array() ) );
		self::assertSame( array(), AB_MCP_Sync_Attachment_Writer::unclear_files( array() ) );
	}

	/** A private method of the writer. */
	private static function writer( string $name, ...$args ) {
		$m = new \ReflectionMethod( AB_MCP_Sync_Attachment_Writer::class, $name );
		return $m->invoke( null, ...$args );
	}

	public function testTheFolderOfAFileIsMadeLevelByLevelUnderTheUploadsAndTheUploadsThemselvesWhereTheyAreMissing(): void {
		// Codex round 1 of branch 10, B8: a site without an upload yet and
		// without folders by month: the uploads folder itself is made.
		$root = $this->dir . '/neu-uploads';
		self::assertTrue( self::writer( 'folder', $root, 'bild.jpg' ) );
		self::assertDirectoryExists( $root );
		self::assertTrue( self::writer( 'folder', $root, '2027/01/bild.jpg' ) );
		self::assertDirectoryExists( $root . '/2027/01' );
		// A1: a link on the way, a file where a folder goes.
		mkdir( $this->dir . '/anderswo' );
		symlink( $this->dir . '/anderswo', $root . '/2027/02' );
		self::assertStringContainsString( 'attachment:unsafe_path', self::writer( 'folder', $root, '2027/02/bild.jpg' )->get_error_message() );
		file_put_contents( $root . '/2027/03', 'x' );
		self::assertStringContainsString( 'attachment:replace_file', self::writer( 'folder', $root, '2027/03/bild.jpg' )->get_error_message() );
		self::assertFileDoesNotExist( $this->dir . '/anderswo/bild.jpg' );
	}

	public function testAFileIsLaidOnlyWhereItIsShownAtItsPathUnderTheUploadsAndAFolderSwappedAsItIsOpenedLeavesItEmptyAndUnclear(): void {
		// Codex round 1 of branch 10, A1: right before the open the folder is
		// asked again; right after it the file opened is the one at its path
		// (device and inode, the resolved path). Another hand swaps the folder
		// for a link between the two: the file is made elsewhere, nothing is
		// written into it, it stays empty, and the step reads it as unclear.
		$root  = $this->dir;
		$bytes = 'the bytes of a file';
		$from  = $this->dir . '/temp';
		file_put_contents( $from, $bytes );
		$file = array( 'path' => '2026/10/a.jpg', 'sha256' => hash( 'sha256', $bytes ), 'size' => strlen( $bytes ) );
		self::assertSame( 'laid', self::writer( 'lay', $root, $root . '/2026/10/a.jpg', $from, $file )['state'] );
		self::assertSame( $bytes, file_get_contents( $root . '/2026/10/a.jpg' ) );
		self::assertSame( 'failed', self::writer( 'lay', $root, $root . '/2026/10/a.jpg', $from, $file )['state'], 'Never over a file there.' );
		// The folder no longer the checked one before the open: nothing made.
		mkdir( $this->dir . '/aussen' );
		rename( $root . '/2026/10', $root . '/2026/10-alt' );
		symlink( $this->dir . '/aussen', $root . '/2026/10' );
		$b = array( 'path' => '2026/10/b.jpg' ) + $file;
		self::assertSame( 'failed', self::writer( 'lay', $root, $root . '/2026/10/b.jpg', $from, $b )['state'] );
		self::assertFileDoesNotExist( $this->dir . '/aussen/b.jpg' );
		unlink( $root . '/2026/10' );
		rename( $root . '/2026/10-alt', $root . '/2026/10' );
		// Swapped between the check and the open.
		AB_MCP_Sync_Attachment_Writer::$probe = function ( $full, $when ) use ( $root ) {
			if ( 'open' === $when ) {
				rename( $root . '/2026/10', $root . '/2026/10-alt' );
				symlink( $this->dir . '/aussen', $root . '/2026/10' );
			}
		};
		try {
			$c    = array( 'path' => '2026/10/c.jpg' ) + $file;
			$laid = self::writer( 'lay', $root, $root . '/2026/10/c.jpg', $from, $c );
		} finally {
			AB_MCP_Sync_Attachment_Writer::$probe = null;
		}
		self::assertSame( 'unclear', $laid['state'] );
		self::assertFalse( $laid['own'] );
		self::assertFileExists( $this->dir . '/aussen/c.jpg', 'Made elsewhere by the open, through the link.' );
		self::assertSame( '', file_get_contents( $this->dir . '/aussen/c.jpg' ), 'Nothing written into it; it stays (nothing is deleted).' );
		// Codex round 2, A1: another hand moves the folder out of the uploads
		// while the file is open: the bytes are written where the folder went
		// (PHP cannot prevent it at an open handle); the place shown again
		// after the writing finds it, the file is unclear, never laid.
		unlink( $root . '/2026/10' );
		rename( $root . '/2026/10-alt', $root . '/2026/10' );
		AB_MCP_Sync_Attachment_Writer::$probe = function ( $full, $when ) use ( $root ) {
			if ( 'written' === $when ) {
				rename( $root . '/2026/10', $this->dir . '/weggezogen' );
			}
		};
		try {
			$d    = array( 'path' => '2026/10/d.jpg' ) + $file;
			$laid = self::writer( 'lay', $root, $root . '/2026/10/d.jpg', $from, $d );
		} finally {
			AB_MCP_Sync_Attachment_Writer::$probe = null;
		}
		self::assertSame( 'unclear', $laid['state'], json_encode( $laid ) );
		self::assertFalse( $laid['own'] );
		self::assertStringContainsString( 'may lie outside the uploads', $laid['note'] );
		self::assertSame( $bytes, file_get_contents( $this->dir . '/weggezogen/d.jpg' ), 'Its own bytes, where the folder went: found and named, not prevented.' );
		// Codex round 3, A1: moved away with a link at the old place to the new
		// one, by another process (PHP's own rename() would empty the realpath
		// cache of this one): lstat() through the link reaches the same inode;
		// realpath(), with its cache emptied, does not resolve to the path.
		mkdir( $root . '/2026/10' );
		AB_MCP_Sync_Attachment_Writer::$probe = function ( $full, $when ) use ( $root ) {
			if ( 'written' === $when ) {
				exec( 'mv ' . escapeshellarg( $root . '/2026/10' ) . ' ' . escapeshellarg( $this->dir . '/umgezogen' ) . ' && ln -s ' . escapeshellarg( $this->dir . '/umgezogen' ) . ' ' . escapeshellarg( $root . '/2026/10' ), $out, $code );
				self::assertSame( 0, $code );
			}
		};
		try {
			$e    = array( 'path' => '2026/10/e.jpg' ) + $file;
			$laid = self::writer( 'lay', $root, $root . '/2026/10/e.jpg', $from, $e );
		} finally {
			AB_MCP_Sync_Attachment_Writer::$probe = null;
		}
		self::assertSame( 'unclear', $laid['state'], json_encode( $laid ) );
		self::assertSame( $bytes, file_get_contents( $this->dir . '/umgezogen/e.jpg' ) );
	}

	public function testTheFileOfANewAttachmentGoesWithItsRowAndThatOfOneHereByItsOwnCall(): void {
		$new = array(
			'op'      => 'create',
			'postarr' => array( 'file' => '2026/10/a.jpg' ),
		);
		self::assertTrue( AB_MCP_Sync_Attachment_Writer::through_row( $new, 'meta:_wp_attached_file' ) );
		self::assertFalse( AB_MCP_Sync_Attachment_Writer::through_row( $new, 'meta:_wp_attachment_metadata' ) );
		self::assertSame( array( 'meta:_wp_attached_file' => \AB_MCP_Sync_Post_Writer::meta_hash( array( '2026/10/a.jpg' ) ) ), AB_MCP_Sync_Attachment_Writer::with_row( $new, null ) );
		$here = array(
			'op'      => 'update',
			'postarr' => array(),
		);
		self::assertFalse( AB_MCP_Sync_Attachment_Writer::through_row( $here, 'meta:_wp_attached_file' ) );
		self::assertSame( array(), AB_MCP_Sync_Attachment_Writer::with_row( $here, 7 ) );
		self::assertSame( array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_wp_attachment_image_alt' ), AB_MCP_Sync_Attachment_Writer::OWN_META );
		self::assertSame( AB_MCP_Sync_Media::META_KEYS, AB_MCP_Sync_Attachment_Writer::FILE_META, 'The keys that name files need upload_files.' );
		self::assertSame( AB_MCP_Sync_Media_Package::MAX_BYTES, AB_MCP_Sync_Attachment_Writer::MAX_BYTES, 'One step lays at most what one package carries.' );
	}

	public function testTheOwnKeysOfAnAttachmentAskTheRightToItAndToUploadForwardAndBack(): void {
		// Decision 28: the keys of the media kind by the rule of their kind;
		// every check of the forward way holds on the way back as well.
		$can                    = array( 'edit_post' );
		$GLOBALS['ab_test_can'] = static function ( string $cap ) use ( &$can ): bool {
			return in_array( $cap, $can, true );
		};
		try {
			$backup = array( 'row' => array(), 'meta' => array(), 'terms' => array() );
			foreach ( array( '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ) as $key ) {
				$may = AB_MCP_Sync_Attachment_Writer::restore_right( 'meta:' . $key, 7, $backup, $backup );
				self::assertIsString( $may, $key );
				self::assertStringContainsString( 'upload_files', $may, $key );
			}
			self::assertTrue( AB_MCP_Sync_Attachment_Writer::restore_right( 'meta:_wp_attachment_image_alt', 7, $backup, $backup ), 'The alternative text with edit_post.' );
			$can = array( 'edit_post', 'upload_files' );
			self::assertTrue( AB_MCP_Sync_Attachment_Writer::restore_right( 'meta:_wp_attachment_metadata', 7, $backup, $backup ) );
			$can = array( 'upload_files' );
			self::assertStringContainsString( 'edit_post', (string) AB_MCP_Sync_Attachment_Writer::restore_right( 'meta:_wp_attachment_image_alt', 7, $backup, $backup ) );
			// Forward: the call of an own key refused right before it, nothing written.
			$prepared = array( 'meta' => array( '_wp_attachment_metadata' => array( array( 'file' => '2026/10/a.jpg' ) ) ) );
			$can      = array( 'edit_post' );
			$refused  = AB_MCP_Sync_Attachment_Writer::write_part( 7, 'meta:_wp_attachment_metadata', $prepared, $backup );
			self::assertInstanceOf( \WP_Error::class, $refused );
			self::assertSame( 'ab_mcp_sync_rights', $refused->get_error_code() );
			self::assertStringContainsString( 'upload_files', $refused->get_error_message() );
			$can     = array();
			$refused = AB_MCP_Sync_Attachment_Writer::write_part( 7, 'meta:_wp_attachment_image_alt', array( 'meta' => array( '_wp_attachment_image_alt' => array( 'x' ) ) ), $backup );
			self::assertSame( 'ab_mcp_sync_rights', $refused->get_error_code() );
		} finally {
			$GLOBALS['ab_test_can'] = null;
		}
	}
}
