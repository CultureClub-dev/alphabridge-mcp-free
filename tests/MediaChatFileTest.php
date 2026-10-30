<?php
/**
 * A file from the chat, the same file twice, a smaller image and finding a
 * file again (4.12.0).
 *
 * ChatGPT hands a file over to the arguments a tool names in
 * _meta["openai/fileParams"], as {download_url, file_id, mime_type,
 * file_name} (Apps SDK reference, read 30.10.2026). wp_upload_media takes it
 * as file and downloads it with the checks of wp_upload_media_from_url. Every
 * file AlphaBridge stores keeps the SHA-256 of the bytes it received; the
 * same file again is not stored a second time unless allow_duplicate says so.
 * wp_find_media finds a file by that hash, its address or its name.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Media;

require_once __DIR__ . '/media-stubs.php';

/** An image editor as wp_get_image_editor() returns it, reduced to what shrink() asks. */
final class MediaTestEditor {
	public $resized = null;
	public $saved   = null;
	public $mime    = null;
	public function __construct( private int $w, private int $h, private bool $fail = false ) {}
	public function get_size() {
		return array( 'width' => $this->w, 'height' => $this->h );
	}
	public function resize( $max_w, $max_h, $crop = false ) {
		$this->resized = array( $max_w, $max_h, $crop );
		$scale         = min( $max_w / $this->w, $max_h / $this->h );
		$this->w       = (int) round( $this->w * $scale );
		$this->h       = (int) round( $this->h * $scale );
		return true;
	}
	public function save( $file = null, $mime_type = null ) {
		$this->mime = $mime_type;
		if ( $this->fail ) {
			return new \WP_Error( 'image_save_error', 'Image file could not be saved.' );
		}
		// As the editor does for a name without a known extension and a type
		// passed: it writes under that type's extension next to it.
		$path = $file . '.png';
		file_put_contents( $path, 'scaled' );
		$this->saved = $path;
		return array( 'path' => $path, 'width' => $this->w, 'height' => $this->h );
	}
}

final class MediaChatFileTest extends TestCase {

	private const PNG = "\x89PNG\r\n\x1a\n-test-image-bytes";

	/** @var array<int, array{hash:string,file:string}> Attachments the WP_Query stub answers from. */
	private array $library = array();

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can']            = static fn(): bool => true;
		$GLOBALS['ab_test_sideloads']      = array();
		$GLOBALS['ab_test_sideload_error'] = false;
		$GLOBALS['ab_test_editor']         = null;
		$GLOBALS['ab_test_attached_files'] = array();
		$GLOBALS['ab_test_attachment_meta'] = array();
		$GLOBALS['ab_test_url_ids']        = array();
		$GLOBALS['ab_test_http']           = array();
		$this->library                     = array();
		$GLOBALS['ab_test_query']          = function ( array $args ) {
			$out = array();
			foreach ( $this->library as $id => $row ) {
				if ( isset( $args['meta_value'] ) && $row['hash'] !== $args['meta_value'] ) {
					continue;
				}
				$out[] = (object) array( 'ID' => $id, 'post_title' => 'File ' . $id, 'post_mime_type' => 'image/png', 'post_date_gmt' => '2026-10-30 20:00:00', 'post_date' => '2026-10-30 21:00:00' );
			}
			// As the database pages: posts_per_page from paged on, -1 for all.
			$per = (int) ( $args['posts_per_page'] ?? -1 );
			return $per > 0 ? array_slice( $out, ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $per, $per ) : $out;
		};
		$this->serve( self::PNG, 'application/octet-stream' );
	}

	protected function tearDown(): void {
		foreach ( $GLOBALS['ab_test_tempfiles'] ?? array() as $f ) {
			@unlink( $f );
			@unlink( $f . '.png' );
		}
		$GLOBALS['ab_test_tempfiles'] = array();
		$GLOBALS['ab_test_query']     = null;
		$GLOBALS['ab_test_editor']    = null;
	}

	private function serve( string $bytes, string $type ): void {
		$GLOBALS['ab_test_http_answer'] = static fn() => ab_test_http_response( 200, $bytes, array( 'Content-Type' => $type ) );
	}

	/** Puts a stored file into the library the stub answers from, as a real upload leaves it. */
	private function stored( int $id, string $bytes ): string {
		$file = tempnam( sys_get_temp_dir(), 'abmcplib' );
		file_put_contents( $file, $bytes );
		$GLOBALS['ab_test_tempfiles'][]          = $file;
		$GLOBALS['ab_test_attached_files'][ $id ] = $file;
		$this->library[ $id ]                    = array( 'hash' => hash( 'sha256', $bytes ), 'file' => $file );
		return $file;
	}

	private static function chat_file( array $extra = array() ): array {
		return array(
			'file' => $extra + array(
				'download_url' => 'https://files.oaiusercontent.com/file-abc?se=2026-10-30&sig=x',
				'file_id'      => 'file_abc',
				'file_name'    => 'photo.png',
				'mime_type'    => 'image/png',
			),
		);
	}

	private static function code( $r ): string {
		return is_wp_error( $r ) ? (string) $r->get_error_code() : '';
	}

	/* ---------------------------------------------------------------- schema */

	public function testTheToolNamesTheFileArgumentForChatGPTAsTheAppsSdkDemands(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Media::register( $r );
		$def = $r->get( 'wp_upload_media' );

		self::assertSame( array( 'openai/fileParams' => array( 'file' ) ), $def['_meta'] );
		$file = $def['inputSchema']['properties']['file'];
		self::assertSame( 'object', $file['type'] );
		// The scan refuses a schema without all four, with others required, or
		// with the two not required.
		self::assertSame( array( 'download_url', 'file_id', 'mime_type', 'file_name' ), array_keys( $file['properties'] ) );
		self::assertSame( array( 'download_url', 'file_id' ), $file['required'] );
		self::assertArrayNotHasKey( 'required', $def['inputSchema'], 'Either file or filename and content, so neither is required on its own.' );

		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$list  = ( new ReflectionMethod( AB_MCP_REST_Controller::class, 'tools_list' ) )->invoke( new AB_MCP_REST_Controller( $r ) );
		$tools = array_column( $list['tools'], null, 'name' );
		self::assertSame( array( 'openai/fileParams' => array( 'file' ) ), $tools['wp_upload_media']['_meta'], 'tools/list hands it to the client.' );
		self::assertArrayNotHasKey( '_meta', $tools['wp_list_media'], 'Only where a tool declares it.' );
	}

	public function testFindMediaReadsAndIsClassifiedSo(): void {
		self::assertTrue( AB_MCP_Tool_Registry::is_read_only( 'wp_find_media', array() ), 'Also by name alone, as the counts in the tests read it.' );
	}

	/* ------------------------------------------------------- file from chat */

	public function testAFileFromTheChatIsDownloadedAndStoredWithItsHash(): void {
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file() + array( 'title' => 'Bakery front' ) );

		self::assertTrue( $r['uploaded'] ?? null, self::code( $r ) );
		self::assertSame( hash( 'sha256', self::PNG ), $r['sha256'] );
		self::assertSame( 'https://files.oaiusercontent.com/file-abc?se=2026-10-30&sig=x', $GLOBALS['ab_test_http'][0]['url'], 'Downloaded from the address the chat gave.' );
		self::assertSame( 3, $GLOBALS['ab_test_http'][0]['args']['redirection'], 'With the limits of wp_upload_media_from_url.' );
		self::assertSame( 'photo.png', $GLOBALS['ab_test_sideloads'][0]['name'] );
		self::assertSame( self::PNG, $GLOBALS['ab_test_sideloads'][0]['bytes'] );
		self::assertSame( 'Bakery front', $GLOBALS['ab_test_sideloads'][0]['title'] );
		self::assertSame( hash( 'sha256', self::PNG ), get_post_meta( $r['id'], AB_MCP_Tools_Media::HASH_META, true ) );
	}

	public function testANameWithoutExtensionTakesItFromTheChatsTypeThenFromTheServer(): void {
		AB_MCP_Tools_Media::upload_base64( self::chat_file( array( 'file_name' => '', 'mime_type' => 'image/jpeg', 'download_url' => 'https://files.example.com/abc' ) ) );
		self::assertSame( 'abc.jpg', $GLOBALS['ab_test_sideloads'][0]['name'] );

		$this->serve( "\x89PNG other", 'image/png' );
		AB_MCP_Tools_Media::upload_base64( self::chat_file( array( 'file_name' => 'scan', 'mime_type' => '', 'download_url' => 'https://files.example.com/def' ) ) );
		self::assertSame( 'scan.png', $GLOBALS['ab_test_sideloads'][1]['name'] );

		$this->serve( 'text', 'text/plain' );
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file( array( 'file_name' => 'notes', 'mime_type' => 'text/plain' ) ) );
		self::assertSame( 'ab_mcp_bad_type', self::code( $r ) );
	}

	public function testAChatFileOnAPrivateHostIsNotFetched(): void {
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file( array( 'download_url' => 'http://127.0.0.1/secret.png' ) ) );
		self::assertSame( 'ab_mcp_blocked_url', self::code( $r ) );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Nothing was requested.' );
	}

	public function testFileAndContentTogetherOrAFileWithoutAddressAreRefused(): void {
		$both = AB_MCP_Tools_Media::upload_base64( self::chat_file() + array( 'filename' => 'a.png', 'content' => base64_encode( self::PNG ) ) );
		self::assertSame( 'ab_mcp_bad_args', self::code( $both ) );
		self::assertSame( 'ab_mcp_bad_args', self::code( AB_MCP_Tools_Media::upload_base64( array( 'file' => array( 'file_id' => 'file_abc' ) ) ) ) );
		self::assertSame( 'ab_mcp_bad_args', self::code( AB_MCP_Tools_Media::upload_base64( array( 'file' => array( 'download_url' => 'https://files.example.com/x' ) ) ) ), 'file_id is required too (Codex, 30.10.2026).' );
		self::assertSame( 'ab_mcp_bad_args', self::code( AB_MCP_Tools_Media::upload_base64( array( 'file' => 'https://example.com/x.png' ) ) ) );
		self::assertSame( 'ab_mcp_missing_arg', self::code( AB_MCP_Tools_Media::upload_base64( array( 'title' => 'nothing' ) ) ), 'Without either, the base64 way names what is missing.' );
		self::assertSame( array(), $GLOBALS['ab_test_sideloads'] );
	}

	public function testThePostIsCheckedBeforeAnythingIsDownloaded(): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'edit_post' !== $cap;
		ab_test_add_post( 7 );
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file() + array( 'post_id' => 7 ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $r ) );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	/* --------------------------------------------------------------- same file */

	public function testTheSameFileIsNotStoredASecondTime(): void {
		$this->stored( 41, self::PNG );
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file() );

		self::assertFalse( $r['uploaded'] );
		self::assertTrue( $r['duplicate'] );
		self::assertSame( 41, $r['id'] );
		self::assertSame( hash( 'sha256', self::PNG ), $r['sha256'] );
		self::assertStringContainsString( 'allow_duplicate: true', $r['note'] );
		self::assertSame( array(), $GLOBALS['ab_test_sideloads'], 'Nothing new in the library.' );

		$base = AB_MCP_Tools_Media::upload_base64( array( 'filename' => 'again.png', 'content' => base64_encode( self::PNG ) ) );
		self::assertSame( 41, $base['id'], 'The same for base64.' );

		$this->serve( self::PNG, 'image/png' );
		$url = AB_MCP_Tools_Media::upload_from_url( array( 'url' => 'https://example.com/same.png' ) );
		self::assertSame( 41, $url['id'], 'And for an address.' );
	}

	public function testAllowDuplicateAddsACopy(): void {
		$this->stored( 41, self::PNG );
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file( array() ) + array( 'allow_duplicate' => true ) );
		self::assertTrue( $r['uploaded'] );
		self::assertCount( 1, $GLOBALS['ab_test_sideloads'] );
	}

	public function testAFileThatIsGoneOrHiddenIsNoDuplicate(): void {
		$file = $this->stored( 41, self::PNG );
		unlink( $file );
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file() );
		self::assertTrue( $r['uploaded'], 'Its file is gone: store it again.' );

		$this->stored( 42, self::PNG );
		$GLOBALS['ab_test_can']       = static fn( string $cap, array $args ): bool => ! ( 'read_post' === $cap && 42 === ( $args[0] ?? 0 ) );
		$GLOBALS['ab_test_sideloads'] = array();
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file() );
		self::assertTrue( $r['uploaded'], 'One the account may not read is not named to it.' );
	}

	public function testUnreadableOrVanishedCopiesDoNotHideALaterOneThatCounts(): void {
		foreach ( array( 41, 42, 43, 44, 45 ) as $id ) {
			$this->stored( $id, self::PNG );
		}
		$this->stored( 46, self::PNG );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => ! ( 'read_post' === $cap && ( $args[0] ?? 0 ) < 46 );
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file() );
		self::assertTrue( $r['duplicate'] ?? false, 'Codex, 30.10.2026: the sixth copy counts.' );
		self::assertSame( 46, $r['id'] );
	}

	/* ------------------------------------------------------------ smaller image */

	public function testMaxSizeScalesALargeImageAndReportsIt(): void {
		$editor                    = new MediaTestEditor( 4000, 3000 );
		$GLOBALS['ab_test_editor'] = static fn() => $editor;
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file() + array( 'max_size' => 1600 ) );

		self::assertSame( array( 1600, 1600, false ), $editor->resized );
		self::assertSame( 'image/png', $editor->mime, 'Saved as PNG again: from the .tmp name alone the editor would write a JPEG (Codex, 30.10.2026).' );
		self::assertSame( array( 'from' => array( 4000, 3000 ), 'to' => array( 1600, 1200 ) ), $r['resized'] );
		self::assertSame( 'scaled', $GLOBALS['ab_test_sideloads'][0]['bytes'], 'The scaled copy is stored.' );
		self::assertSame( hash( 'sha256', self::PNG ), $r['sha256'], 'The hash is the one of the file as received.' );
	}

	public function testMaxSizeLeavesSmallImagesGifsPdfsAndUnreadableImagesAlone(): void {
		$editor                    = new MediaTestEditor( 800, 600 );
		$GLOBALS['ab_test_editor'] = static fn() => $editor;
		$r = AB_MCP_Tools_Media::upload_base64( self::chat_file() + array( 'max_size' => 1600 ) );
		self::assertNull( $editor->resized );
		self::assertArrayNotHasKey( 'resized', $r );

		$gif = new MediaTestEditor( 4000, 3000 );
		$GLOBALS['ab_test_editor'] = static fn() => $gif;
		AB_MCP_Tools_Media::upload_base64( array( 'filename' => 'anim.gif', 'content' => base64_encode( 'GIF89a-frames' ), 'max_size' => 500 ) );
		self::assertNull( $gif->resized, 'A GIF keeps its frames.' );

		$GLOBALS['ab_test_editor'] = null;
		$r = AB_MCP_Tools_Media::upload_base64( array( 'filename' => 'big.png', 'content' => base64_encode( 'PNG-unreadable' ), 'max_size' => 500 ) );
		self::assertTrue( $r['uploaded'] );
		self::assertStringContainsString( 'max_size was not applied', $r['warning'] );

		$failing = new MediaTestEditor( 4000, 3000, true );
		$GLOBALS['ab_test_editor'] = static fn() => $failing;
		$r = AB_MCP_Tools_Media::upload_base64( array( 'filename' => 'big2.png', 'content' => base64_encode( 'PNG-unsaved' ), 'max_size' => 500 ) );
		self::assertTrue( $r['uploaded'] );
		self::assertSame( 'PNG-unsaved', end( $GLOBALS['ab_test_sideloads'] )['bytes'], 'Stored as it came.' );
	}

	/* --------------------------------------------------------------- find media */

	public function testFindMediaByHash(): void {
		$this->stored( 41, self::PNG );
		$this->stored( 43, 'other' );
		update_post_meta( 41, AB_MCP_Tools_Media::HASH_META, hash( 'sha256', self::PNG ) );
		$queries = array();
		$inner   = $GLOBALS['ab_test_query'];
		$GLOBALS['ab_test_query'] = static function ( array $args ) use ( &$queries, $inner ) {
			$queries[] = $args;
			$meta      = $args['meta_query'][0] ?? array();
			if ( AB_MCP_Tools_Media::HASH_META === ( $meta['key'] ?? '' ) ) {
				$args['meta_value'] = $meta['value'];
			}
			return $inner( $args );
		};
		$r = AB_MCP_Tools_Media::find_media( array( 'sha256' => strtoupper( hash( 'sha256', self::PNG ) ) ) );

		self::assertSame( array( 41 ), array_column( $r['items'], 'id' ) );
		self::assertSame( hash( 'sha256', self::PNG ), $r['items'][0]['sha256'] );
		self::assertSame( 'inherit', $queries[0]['post_status'] );
		self::assertSame( 'attachment', $queries[0]['post_type'] );
	}

	public function testFindMediaByTheAddressOfASizeOrOfTheScaledOriginal(): void {
		$GLOBALS['ab_test_url_ids']['https://example.test/wp-content/uploads/2026/10/front.png'] = 41;
		$GLOBALS['ab_test_url_ids']['https://example.test/wp-content/uploads/2026/10/big-scaled.jpg'] = 44;
		$GLOBALS['ab_test_attachment_meta'][41] = array( 'sizes' => array( 'medium' => array( 'file' => 'front-300x200.png' ) ) );
		$GLOBALS['ab_test_attachment_meta'][44] = array( 'original_image' => 'big.jpg' );
		$seen = array();
		$GLOBALS['ab_test_query'] = static function ( array $args ) use ( &$seen ) {
			$seen[] = $args['post__in'] ?? null;
			return array();
		};
		AB_MCP_Tools_Media::find_media( array( 'url' => 'https://example.test/wp-content/uploads/2026/10/front-300x200.png' ) );
		AB_MCP_Tools_Media::find_media( array( 'url' => 'https://example.test/wp-content/uploads/2026/10/big.jpg' ) );
		self::assertSame( array( array( 41 ), array( 44 ) ), $seen );

		$none = AB_MCP_Tools_Media::find_media( array( 'url' => 'https://example.test/nothing.png' ) );
		self::assertSame( array( 'items' => array() ), $none );
		$made_up = AB_MCP_Tools_Media::find_media( array( 'url' => 'https://example.test/wp-content/uploads/2026/10/front-9999x8888.png' ) );
		self::assertSame( array( 'items' => array() ), $made_up, 'A size the metadata does not name is no match (Codex, 30.10.2026).' );
		self::assertCount( 2, $seen, 'An unknown address asks nothing more.' );
	}

	public function testFindMediaByNameAndTextAndItsRefusals(): void {
		$seen = array();
		$GLOBALS['ab_test_query'] = static function ( array $args ) use ( &$seen ) {
			$seen[] = $args;
			return array();
		};
		AB_MCP_Tools_Media::find_media( array( 'filename' => 'logo', 'search' => 'bakery', 'mime' => 'image', 'per_page' => 500 ) );
		self::assertSame( array( array( 'key' => '_wp_attached_file', 'value' => 'logo', 'compare' => 'LIKE' ) ), $seen[0]['meta_query'] );
		self::assertSame( 'bakery', $seen[0]['s'] );
		self::assertSame( 'image', $seen[0]['post_mime_type'] );
		self::assertSame( 100, $seen[0]['posts_per_page'] );

		self::assertSame( 'ab_mcp_missing_arg', self::code( AB_MCP_Tools_Media::find_media( array() ) ) );
		self::assertSame( 'ab_mcp_bad_args', self::code( AB_MCP_Tools_Media::find_media( array( 'sha256' => 'abc' ) ) ) );
		self::assertCount( 1, $seen );
	}

	public function testFindMediaPagesOnPastFilesTheAccountMayNotRead(): void {
		foreach ( array( 41, 42, 43, 44, 45, 46 ) as $id ) {
			$this->stored( $id, 'f' . $id );
		}
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => ( $args[0] ?? 0 ) >= 46;
		$r = AB_MCP_Tools_Media::find_media( array( 'search' => 'file', 'per_page' => 1 ) );
		self::assertSame( array( 46 ), array_column( $r['items'], 'id' ), 'Codex, 30.10.2026: five it may not read come first.' );
	}

	public function testFindMediaSaysSoWhereItStoppedBeforeTheEnd(): void {
		for ( $id = 100; $id < 100 + AB_MCP_Tools_Media::FIND_PAGES + 1; $id++ ) {
			$this->stored( $id, 'g' . $id );
		}
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => ( $args[0] ?? 0 ) === 100 + AB_MCP_Tools_Media::FIND_PAGES;
		$r = AB_MCP_Tools_Media::find_media( array( 'search' => 'file', 'per_page' => 1 ) );
		self::assertSame( array(), $r['items'] );
		self::assertTrue( $r['more'], 'Codex, 30.10.2026: an empty list after the limit must not read as «nothing».' );
		self::assertStringContainsString( 'Narrow it', $r['note'] );

		$r = AB_MCP_Tools_Media::find_media( array( 'search' => 'nothing-readable', 'per_page' => 100 ) );
		self::assertArrayNotHasKey( 'more', $r, 'Matches that ran out are no stop.' );
	}

	public function testFindMediaOrdersSoThePagesNeitherRepeatNorSkip(): void {
		$seen = array();
		$GLOBALS['ab_test_query'] = static function ( array $args ) use ( &$seen ) {
			$seen[] = $args;
			return array();
		};
		AB_MCP_Tools_Media::find_media( array( 'search' => 'x' ) );
		self::assertSame( array( 'date' => 'DESC', 'ID' => 'DESC' ), $seen[0]['orderby'] );
	}

	public function testFindMediaListsOnlyWhatTheAccountMayRead(): void {
		$this->stored( 41, self::PNG );
		$this->stored( 42, 'two' );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 42 !== ( $args[0] ?? 0 );
		$r = AB_MCP_Tools_Media::find_media( array( 'search' => 'file' ) );
		self::assertSame( array( 41 ), array_column( $r['items'], 'id' ) );
		self::assertNull( $r['items'][0]['sha256'], 'A file without a stored hash says so.' );
	}
}
