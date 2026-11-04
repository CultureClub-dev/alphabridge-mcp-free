<?php
/**
 * The file list of an attachment (AB_MCP_Sync_Media, decision 26) against
 * wp_delete_attachment_files() of WordPress 7.1.3: an image with its sizes,
 * the original of a scaled image, the companions of the client-side media
 * flow, a thumb and two edits of the backup sizes — the list is exactly
 * what the core function would delete (measured through the filter
 * wp_delete_file, which here deletes nothing), each file with its size and
 * SHA-256; and what locks: a missing file, a path out of the uploads, a
 * symbolic link, an absolute path, a path another attachment names.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Media;
use PHPUnit\Framework\TestCase;

final class SyncMediaListTest extends TestCase {

	/** @var string[] Files this test made. */
	private $made = array();

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	protected function tearDown(): void {
		remove_all_filters( 'big_image_size_threshold' );
		remove_all_filters( 'wp_delete_file' );
		foreach ( array_reverse( $this->made ) as $file ) {
			if ( is_link( $file ) || is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->made = array();
	}

	public static function tearDownAfterClass(): void {
		wp_set_current_user( 0 );
		AB_MCP_IT_Sites::reset( 'a' );
	}

	/** An image attachment with its sizes, made as an upload makes it. */
	private function image( string $name, int $width = 800, int $height = 600 ): int {
		$gd = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $gd, 0, 0, $width, $height, imagecolorallocate( $gd, 40, 120, 200 ) );
		ob_start();
		imagejpeg( $gd );
		$bytes = (string) ob_get_clean();
		$up    = wp_upload_bits( $name . '.jpg', null, $bytes );
		self::assertFalse( $up['error'] );
		$this->made[] = $up['file'];
		$id           = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $up['file'] );
		self::assertIsInt( $id );
		$meta = wp_generate_attachment_metadata( $id, $up['file'] );
		wp_update_attachment_metadata( $id, $meta );
		foreach ( $this->files_of( $id ) as $file ) {
			$this->made[] = $file;
		}
		return $id;
	}

	/** Every file the core made for an attachment, by its metadata. */
	private function files_of( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		$dir  = dirname( get_attached_file( $id ) );
		$out  = array( get_attached_file( $id ) );
		foreach ( $meta['sizes'] ?? array() as $size ) {
			$out[] = $dir . '/' . $size['file'];
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$out[] = $dir . '/' . $meta['original_image'];
		}
		return $out;
	}

	private function put( string $path, string $bytes ): void {
		file_put_contents( $path, $bytes );
		$this->made[] = $path;
	}

	/** What wp_delete_attachment_files() would delete, relative to the uploads. */
	private static function core_list( int $id ): array {
		$deleted = array();
		add_filter(
			'wp_delete_file',
			static function ( $file ) use ( &$deleted ) {
				$deleted[] = $file;
				return '';
			}
		);
		wp_delete_attachment_files( $id, wp_get_attachment_metadata( $id ), get_post_meta( $id, '_wp_attachment_backup_sizes', true ), get_attached_file( $id ) );
		remove_all_filters( 'wp_delete_file' );
		$base = realpath( wp_get_upload_dir()['basedir'] );
		$out  = array();
		foreach ( $deleted as $file ) {
			$out[] = substr( (string) realpath( $file ), strlen( $base ) + 1 );
		}
		sort( $out );
		return $out;
	}

	public function testTheListIsWhatWpDeleteAttachmentFilesDeletes(): void {
		add_filter( 'big_image_size_threshold', static fn() => 500 );
		$id   = $this->image( 'kanon-liste' );
		$meta = wp_get_attachment_metadata( $id );
		self::assertNotEmpty( $meta['original_image'], 'The core scaled the image and kept the original.' );
		self::assertNotEmpty( $meta['sizes'] );
		$dir = dirname( get_attached_file( $id ) );
		foreach ( array( 'thumb' => 'kanon-liste-thumb.jpg', 'source_image' => 'kanon-liste.heic', 'animated_video' => 'kanon-liste.mp4', 'animated_video_poster' => 'kanon-liste-poster.jpg' ) as $key => $file ) {
			$this->put( "$dir/$file", $key );
			$meta[ $key ] = $file;
		}
		wp_update_attachment_metadata( $id, $meta );
		$this->put( "$dir/kanon-liste-e1700000000.jpg", 'backup full' );
		$this->put( "$dir/kanon-liste-150x150-e1700000000.jpg", 'backup thumbnail' );
		update_post_meta(
			$id,
			'_wp_attachment_backup_sizes',
			array(
				'full-orig'      => array( 'file' => 'kanon-liste-e1700000000.jpg', 'width' => 800, 'height' => 600 ),
				'thumbnail-orig' => array( 'file' => 'kanon-liste-150x150-e1700000000.jpg', 'width' => 150, 'height' => 150 ),
			)
		);

		$list = AB_MCP_Sync_Media::files( $id );
		self::assertIsArray( $list );
		self::assertSame( array(), $list['problems'] );
		$mine = array_column( $list['files'], 'path' );
		sort( $mine );
		$core = self::core_list( $id );
		self::assertSame( $core, $mine, 'Exactly the files wp_delete_attachment_files() knows.' );
		self::assertGreaterThanOrEqual( 2 + count( $meta['sizes'] ) + 4 + 2, count( $mine ) );

		$base  = wp_get_upload_dir()['basedir'];
		$roles = array();
		foreach ( $list['files'] as $file ) {
			self::assertFileExists( $base . '/' . $file['path'], 'Nothing was deleted.' );
			self::assertSame( hash_file( 'sha256', $base . '/' . $file['path'] ), $file['sha256'] );
			self::assertSame( filesize( $base . '/' . $file['path'] ), $file['size'] );
			$roles = array_merge( $roles, $file['roles'] );
		}
		foreach ( array( 'file', 'thumb', 'original_image', 'source_image', 'animated_video', 'animated_video_poster', 'backup:full-orig', 'backup:thumbnail-orig', 'size:thumbnail', 'size:medium' ) as $role ) {
			self::assertContains( $role, $roles );
		}
	}

	public function testAMissingFileALinkAPathOutOfTheUploadsAndAnAbsolutePathLock(): void {
		$id   = $this->image( 'kanon-sperre' );
		$meta = wp_get_attachment_metadata( $id );
		$dir  = dirname( get_attached_file( $id ) );

		// A size file gone.
		unlink( $dir . '/' . $meta['sizes']['thumbnail']['file'] );
		$list = AB_MCP_Sync_Media::files( $id );
		self::assertSame( array( 'missing' ), array_column( $list['problems'], 'problem' ) );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Media::note( $acc, $list );
		self::assertSame( array( 'attachment:missing_file' ), array_column( $acc['locks'], 'key' ) );

		// A size outside the uploads, by the core's own str_replace().
		$meta['sizes']['evil'] = array( 'file' => '../../../wp-config.php' );
		wp_update_attachment_metadata( $id, $meta );
		$list = AB_MCP_Sync_Media::files( $id );
		self::assertContains( 'unsafe', array_column( $list['problems'], 'problem' ) );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Media::note( $acc, $list );
		self::assertContains( 'attachment:unsafe_path', array_column( $acc['locks'], 'key' ) );
		unset( $meta['sizes']['evil'] );

		// A size that is a symbolic link to a file elsewhere.
		$outside = AB_MCP_IT_Sites::docroot( 'a' ) . '/kanon-ausserhalb.txt';
		$this->put( $outside, 'geheim' );
		$link = $dir . '/kanon-sperre-link.jpg';
		symlink( $outside, $link );
		$this->made[]          = $link;
		$meta['sizes']['link'] = array( 'file' => 'kanon-sperre-link.jpg' );
		wp_update_attachment_metadata( $id, $meta );
		$list = AB_MCP_Sync_Media::files( $id );
		self::assertContains( 'link', array_column( $list['problems'], 'problem' ) );
		foreach ( $list['files'] as $file ) {
			self::assertNotSame( 'kanon-sperre-link.jpg', basename( $file['path'] ), 'A link is never read.' );
		}

		// An absolute path in _wp_attached_file.
		update_post_meta( $id, '_wp_attached_file', $dir . '/kanon-sperre.jpg' );
		$list = AB_MCP_Sync_Media::files( $id );
		self::assertSame( array(), $list['files'] );
		self::assertSame( 'unsafe', $list['problems'][0]['problem'] );
	}

	public function testTheReverseMapGoesOnPastAPageTheReaderCuts(): void {
		global $wpdb;
		$rows = array();
		for ( $i = 0; $i < 501; $i++ ) {
			$rows[] = $wpdb->prepare( "('attachment', 'inherit', %s, '', '', '', '', '')", "seite-$i" );
		}
		$wpdb->query( "INSERT INTO {$wpdb->posts} (post_type, post_status, post_title, post_content, post_excerpt, to_ping, pinged, post_content_filtered) VALUES " . implode( ', ', $rows ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids  = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID" ) );
		$meta = array();
		foreach ( $ids as $id ) {
			$meta[] = $wpdb->prepare( "(%d, '_wp_attached_file', %s)", $id, "2026/11/seite-$id.jpg" );
		}
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $meta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::assertCount( 501, $ids );

		$first = AB_MCP_Sync_Media::reverse( 0, 1000 );
		self::assertCount( 500, $first['paths'], 'A page of the reader holds at most 500.' );
		self::assertNotNull( $first['cursor'], 'A full page of the reader is no end, whatever was asked.' );
		$second = AB_MCP_Sync_Media::reverse( (int) $first['cursor'], 1000 );
		self::assertCount( 1, $second['paths'] );
		self::assertNull( $second['cursor'] );
	}

	public function testAListTheReverseMapCannotReadLeavesItIncompleteAndLocks(): void {
		global $wpdb;
		$good = $this->image( 'kanon-lesbar' );
		$bad  = $this->image( 'kanon-kaputt' );
		// The metadata of the second does not unpack (a count that does not
		// match): its thumb, which the first names as well, is not seen.
		$meta          = wp_get_attachment_metadata( $good );
		$dir           = dirname( get_attached_file( $good ) );
		$this->put( "$dir/kanon-geteilt-zwei.jpg", 'thumb' );
		$meta['thumb'] = 'kanon-geteilt-zwei.jpg';
		wp_update_attachment_metadata( $good, $meta );
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'a:1:{s:5:"thumb";s:22:"kanon-geteilt-zwei.jpg";i:1;i:2;}' ), array( 'post_id' => $bad, 'meta_key' => '_wp_attachment_metadata' ) );
		wp_cache_flush();

		$map = AB_MCP_Sync_Media::reverse( 0, 100 );
		self::assertFalse( $map['complete'], 'Not every list could be read.' );
		self::assertSame( array( $bad ), $map['unreadable'] );
		foreach ( $map['paths'] as $ids ) {
			self::assertNotContains( $bad, $ids, 'An unreadable list names no path.' );
		}
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Media::note_shared( $acc, $bad, AB_MCP_Sync_Media::files( $bad ), $map['paths'], $map['unreadable'] );
		self::assertContains( '*:unreadable_value', array_column( $acc['locks'], 'key' ), 'The attachment whose list is not known locks.' );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Media::note( $acc, AB_MCP_Sync_Media::files( $bad ) );
		self::assertContains( '*:unreadable_value', array_column( $acc['locks'], 'key' ) );
	}

	public function testAPathTwoAttachmentsNameLocksBoth(): void {
		$first  = $this->image( 'kanon-geteilt' );
		$second = $this->image( 'kanon-zwei' );
		$meta   = wp_get_attachment_metadata( $first );
		$dir    = dirname( get_attached_file( $first ) );
		$this->put( "$dir/kanon-geteilt-thumb.jpg", 'thumb' );
		$meta['thumb'] = 'kanon-geteilt-thumb.jpg';
		wp_update_attachment_metadata( $first, $meta );
		$other          = wp_get_attachment_metadata( $second );
		$other['thumb'] = 'kanon-geteilt-thumb.jpg';
		wp_update_attachment_metadata( $second, $other );
		// The core keeps a thumb another attachment names: its own list says so.
		self::assertNotContains( "$dir/kanon-geteilt-thumb.jpg", array_map( static fn( $p ) => wp_get_upload_dir()['basedir'] . '/' . $p, self::core_list( $first ) ) );

		$paths  = array();
		$cursor = 0;
		do {
			$page = AB_MCP_Sync_Media::reverse( (int) $cursor, 1 );
			self::assertIsArray( $page );
			foreach ( $page['paths'] as $path => $ids ) {
				$paths[ $path ] = array_merge( $paths[ $path ] ?? array(), $ids );
			}
			$cursor = $page['cursor'];
		} while ( null !== $cursor );
		$shared = AB_MCP_Sync_Media::shared( $paths );
		$thumb  = substr( "$dir/kanon-geteilt-thumb.jpg", strlen( wp_get_upload_dir()['basedir'] ) + 1 );
		self::assertSame( array( $thumb => array( $first, $second ) ), $shared );
		foreach ( array( $first, $second ) as $id ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Media::note_shared( $acc, $id, AB_MCP_Sync_Media::files( $id ), $paths );
			self::assertSame( array( 'attachment:shared_path' ), array_values( array_unique( array_column( $acc['locks'], 'key' ) ) ), "Attachment $id." );
		}
	}
}
