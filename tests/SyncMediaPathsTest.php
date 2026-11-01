<?php
/**
 * The file list of an attachment worked out as wp_delete_attachment_files()
 * does (AB_MCP_Sync_Media::paths(), Z8 decision 26), one role at a time, and
 * the path rules: relative, no "..", no absolute start, no NUL, backslash
 * or control character; facts only for regular files under the uploads
 * without a link on the way; the reverse map of shared paths. Against the
 * core function itself in tests/integration/SyncMediaListTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Media;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/sync-canon-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-media.php';

final class SyncMediaPathsTest extends TestCase {

	private const BASE = '/srv/site/wp-content/uploads';

	/** @var string Temporary uploads folder. */
	private $dir = '';

	protected function tearDown(): void {
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $file ) {
				( $file->isDir() && ! $file->isLink() ) ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->dir );
		}
	}

	private static function list_of( $attached, $meta, $backup = null ): array {
		$out = AB_MCP_Sync_Media::paths( $attached, $meta, $backup, self::BASE );
		$map = array();
		foreach ( $out['files'] as $file ) {
			$map[ $file['path'] ] = $file['roles'];
		}
		return array( $map, array_column( $out['problems'], 'problem', 'role' ) );
	}

	public function testEveryRoleTheCoreDeletes(): void {
		list( $files, $problems ) = self::list_of(
			'2026/11/bild-scaled.jpg',
			array(
				'file'                  => '2026/11/bild-scaled.jpg',
				'thumb'                 => 'bild-thumb.jpg',
				'sizes'                 => array(
					'thumbnail'    => array( 'file' => 'bild-150x150.jpg' ),
					'medium'       => array( 'file' => 'bild-300x200.jpg' ),
					'medium_large' => array( 'file' => 'bild-300x200.jpg' ),
					'broken'       => array( 'width' => 1 ),
				),
				'original_image'        => 'bild.jpg',
				'source_image'          => 'bild.heic',
				'animated_video'        => 'bild.mp4',
				'animated_video_poster' => 'bild-poster.jpg',
			),
			array(
				'full-orig'      => array( 'file' => 'bild-e1.jpg' ),
				'thumbnail-orig' => array( 'file' => 'bild-150x150-e1.jpg' ),
			)
		);
		self::assertSame( array(), $problems );
		self::assertSame(
			array(
				'2026/11/bild-thumb.jpg'          => array( 'thumb' ),
				'2026/11/bild-150x150.jpg'        => array( 'size:thumbnail' ),
				'2026/11/bild-300x200.jpg'        => array( 'size:medium', 'size:medium_large' ),
				'2026/11/bild.jpg'                => array( 'original_image' ),
				'2026/11/bild.heic'               => array( 'source_image' ),
				'2026/11/bild.mp4'                => array( 'animated_video' ),
				'2026/11/bild-poster.jpg'         => array( 'animated_video_poster' ),
				'2026/11/bild-e1.jpg'             => array( 'backup:full-orig' ),
				'2026/11/bild-150x150-e1.jpg'     => array( 'backup:thumbnail-orig' ),
				'2026/11/bild-scaled.jpg'         => array( 'file' ),
			),
			$files
		);
	}

	public function testTheBackupsStandBesideTheFileOfTheMetadataAsInTheCore(): void {
		list( $files ) = self::list_of( 'bild.jpg', array( 'file' => 'bild.jpg' ), array( 'full-orig' => array( 'file' => 'bild-e1.jpg' ) ) );
		self::assertSame( array( 'bild-e1.jpg' => array( 'backup:full-orig' ), 'bild.jpg' => array( 'file' ) ), $files, 'path_join( ".", … ) of the uploads folder itself.' );
		list( $files ) = self::list_of( '2026/11/a.jpg', array( 'file' => '2025/01/a.jpg' ), array( 'full-orig' => array( 'file' => 'a-e1.jpg' ) ) );
		self::assertArrayHasKey( '2025/01/a-e1.jpg', $files, 'The dirname of the metadata, not of _wp_attached_file.' );
		// Companions only as text, as the core takes them.
		list( $files ) = self::list_of( 'a.jpg', array( 'source_image' => array( 'x' ), 'animated_video' => 5 ) );
		self::assertSame( array( 'a.jpg' => array( 'file' ) ), $files );
	}

	public static function unsafe(): array {
		return array(
			'out of the uploads'  => array( 'a.jpg', array( 'sizes' => array( 'x' => array( 'file' => '../../wp-config.php' ) ) ), 'size:x' ),
			'an absolute size'    => array( 'a.jpg', array( 'thumb' => '/etc/passwd' ), 'thumb' ),
			'a backslash'         => array( 'a.jpg', array( 'original_image' => 'a\\b.jpg' ), 'original_image' ),
			'a NUL'               => array( 'a.jpg', array( 'source_image' => "a\0.jpg" ), 'source_image' ),
			'a control character' => array( 'a.jpg', array( 'animated_video' => "a\n.mp4" ), 'animated_video' ),
			'an absolute file'    => array( '/srv/site/wp-content/uploads/a.jpg', array( 'sizes' => array( 'thumbnail' => array( 'file' => 'a-150x150.jpg' ) ) ), 'file' ),
			'a drive'             => array( 'C:\\a.jpg', array(), 'file' ),
			'an empty file'       => array( '', array(), 'file' ),
		);
	}

	#[DataProvider( 'unsafe' )]
	public function testAPathOutsideTheRulesIsAProblemNotAFile( string $attached, array $meta, string $role ): void {
		list( $files, $problems ) = self::list_of( $attached, $meta );
		self::assertSame( 'unsafe', $problems[ $role ] ?? null );
		foreach ( array_keys( $files ) as $path ) {
			self::assertTrue( AB_MCP_Sync_Media::safe_path( $path ), $path );
		}
	}

	public function testAnAbsoluteAttachedFileListsNothingAndNamesOnlyItself(): void {
		$out = AB_MCP_Sync_Media::paths( '/srv/site/wp-content/uploads/a.jpg', array( 'thumb' => 'a-t.jpg', 'sizes' => array( 'thumbnail' => array( 'file' => 'a-150x150.jpg' ) ) ), null, self::BASE );
		self::assertSame( array(), $out['files'] );
		self::assertSame( array( array( 'path' => '/srv/site/wp-content/uploads/a.jpg', 'role' => 'file', 'problem' => 'unsafe' ) ), $out['problems'] );
	}

	public function testThePathRules(): void {
		foreach ( array( '2026/11/a.jpg', 'a.jpg', 'ä ö.jpg' ) as $ok ) {
			self::assertTrue( AB_MCP_Sync_Media::safe_path( $ok ), $ok );
		}
		foreach ( array( '', '/a.jpg', '../a.jpg', 'a/../b.jpg', 'a/./b.jpg', 'a//b.jpg', "a\0b", 'a\\b', "a\x1fb", "a\x7fb", 'C:/a.jpg', 'a/', '..' ) as $bad ) {
			self::assertFalse( AB_MCP_Sync_Media::safe_path( $bad ), json_encode( $bad ) );
		}
	}

	public function testFactsOnlyForRegularFilesUnderTheUploadsWithoutALink(): void {
		$this->dir = sys_get_temp_dir() . '/ab-sync-media-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir . '/uploads/2026', 0777, true );
		file_put_contents( $this->dir . '/uploads/2026/a.jpg', 'bild' );
		file_put_contents( $this->dir . '/outside.txt', 'geheim' );
		symlink( $this->dir . '/outside.txt', $this->dir . '/uploads/2026/link.jpg' );
		mkdir( $this->dir . '/real' );
		file_put_contents( $this->dir . '/real/b.jpg', 'b' );
		symlink( $this->dir . '/real', $this->dir . '/uploads/linked' );
		mkdir( $this->dir . '/uploads/2026/dir.jpg' );
		symlink( $this->dir . '/nowhere.jpg', $this->dir . '/uploads/2026/dangling.jpg' );
		$list  = array(
			'files'    => array(
				array( 'path' => '2026/a.jpg', 'roles' => array( 'file' ) ),
				array( 'path' => '2026/link.jpg', 'roles' => array( 'thumb' ) ),
				array( 'path' => 'linked/b.jpg', 'roles' => array( 'size:x' ) ),
				array( 'path' => '2026/dir.jpg', 'roles' => array( 'size:y' ) ),
				array( 'path' => '2026/gone.jpg', 'roles' => array( 'size:z' ) ),
				array( 'path' => '2026/dangling.jpg', 'roles' => array( 'size:w' ) ),
			),
			'problems' => array(),
		);
		$facts = AB_MCP_Sync_Media::facts( $list, $this->dir . '/uploads' );
		self::assertSame( array( array( 'path' => '2026/a.jpg', 'roles' => array( 'file' ), 'size' => 4, 'sha256' => hash( 'sha256', 'bild' ) ) ), $facts['files'] );
		self::assertSame( array( 'thumb' => 'link', 'size:x' => 'link', 'size:y' => 'not_file', 'size:z' => 'missing', 'size:w' => 'link' ), array_column( $facts['problems'], 'problem', 'role' ), 'A link that leads nowhere is a link, not a missing file.' );
	}

	public function testAPathTwoAttachmentsNameIsShared(): void {
		self::assertSame( array( 'a/t.jpg' => array( 3, 7 ) ), AB_MCP_Sync_Media::shared( array( 'a/t.jpg' => array( 7, 3, 7 ), 'a/x.jpg' => array( 3 ) ) ) );
		$acc = \AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Media::note_shared( $acc, 3, array( 'files' => array( array( 'path' => 'a/t.jpg' ), array( 'path' => 'a/x.jpg' ) ) ), array( 'a/t.jpg' => array( 3, 7 ), 'a/x.jpg' => array( 3 ) ) );
		self::assertSame( array( 'attachment:shared_path' ), array_column( $acc['locks'], 'key' ) );
		self::assertSame( 'file:a/t.jpg', $acc['locks'][0]['at'] );
	}
}
