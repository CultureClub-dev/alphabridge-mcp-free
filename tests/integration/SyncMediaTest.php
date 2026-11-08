<?php
/**
 * Z8 branch 10 (heikel/z8-medien) on two WordPress sites: the kind
 * attachment with its files (decision 26), end to end through
 * wp_site_export (purpose objects and media), wp_site_import_chunk,
 * wp_site_push and wp_site_revert. Site B is the staging site, in processes
 * of its own; site A, this process, the production site.
 *
 * - The normal case: a new image with the sizes the staging site made and
 *   a new PDF, both in a new page that shows the image as its featured
 *   image and in a block and links the PDF, the image hanging under the
 *   page (the parent step after the page). Every file is laid exclusively,
 *   byte for byte as on staging; the way back leaves the three new objects
 *   and every file as rests, named.
 * - A changed title, alternative text and description of an attachment
 *   both sites have, and a new page it hangs under: the page first, then
 *   only the row and meta of the attachment, no file; the way back puts
 *   them back byte for byte.
 * - What locks before anything is written: another file at a path, a path
 *   another attachment names (also by an absolute path of old, or a list
 *   that does not read whole), a link on the way, a file of a type the
 *   account may not upload by its name or its content (also one that lies
 *   there already); a path taken between the check and the laying refuses
 *   the step, the files laid before stay as the run's own, no attachment
 *   is made; a folder swapped for a link as a file is opened leaves the
 *   file empty and unclear. No step deletes anything.
 * - A site without an upload and without folders by month; the rights
 *   asked again right before a file is laid.
 * - A file a hook changes as a step writes or puts back deviates the
 *   attachment and stops its way back (decision A).
 * - The package of media: lines, index, a name that is no UTF-8, paths
 *   checked for credentials, the lines of one package.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Protected_Store;
use AB_MCP_REST_Controller;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Backup;
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Exclusions;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Resources;
use AB_MCP_Sync_Schema;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-revert.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncMediaTest extends TestCase {

	/**
	 * The uploads of both sites in these cases, relative to their WordPress
	 * (the option upload_path): a root of this class alone, made anew for
	 * each case and removed after it. The integration suite runs every class
	 * against the same two sites; another class may leave files in
	 * wp-content/uploads, and these cases neither assume those empty nor
	 * touch them (CI run 42480445606 of free#140). A filter of the uploads
	 * would lock the media (AB_MCP_Sync_Media::UPLOAD_HOOKS); the option
	 * does not.
	 */
	private const UPLOADS = 'wp-content/uploads-medien';

	/** The date every object of these tests carries, on both sites. */
	private const DATE = '2026-10-01 10:00:00';

	/** A PDF of one empty page, as small as a reader opens it. */
	private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 200 200]/Parent 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	/** @var array[] The deletions the filters of a case saw. */
	private $deletions = array();

	/** @var string[] The files of the uploads of A before the case. */
	private $files_before = array();

	/** @var callable|null The filter of user_has_cap a case added (only it is removed: the core has its own there). */
	private $caps_filter = null;

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		// The root of this class on both sites, clear of what a case of it
		// left before (a run that ended early), never another's files.
		self::empty_uploads();
		self::remove_outside();
		update_option( 'upload_path', self::UPLOADS );
		wp_upload_dir( null, false, true );
		self::b( array( array( 'op' => 'update_option', 'name' => 'upload_path', 'value' => self::UPLOADS ) ) );
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		AB_MCP_Sync_Backup::forget();
		AB_MCP_Sync_Guard::disarm();
		delete_option( AB_MCP_Sync_Guard::OPTION );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		wp_set_current_user( self::admin() );
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
		$this->deletions = array();
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	protected function tearDown(): void {
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Backup::forget();
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Engine::$probe    = null;
		\AB_MCP_Sync_Attachment_Writer::$probe = null;
		AB_MCP_Sync_Resources::$probe = null;
		AB_MCP_Sync_Resources::$time  = null;
		delete_option( \AB_MCP_Change_Lock::OPTION );
		remove_all_filters( 'pre_delete_post' );
		remove_all_filters( 'pre_trash_post' );
		remove_all_filters( 'wp_delete_file' );
		remove_all_filters( 'upload_mimes' );
		remove_all_filters( 'mime_types' );
		remove_all_actions( 'delete_option' );
		remove_all_actions( 'add_attachment' );
		remove_all_filters( 'wp_check_filetype_and_ext' );
		self::remove_outside();
		if ( null !== $this->caps_filter ) {
			remove_filter( 'user_has_cap', $this->caps_filter );
			$this->caps_filter = null;
		}
		self::empty_uploads();
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		// The uploads as the sites have them again (the option is gone with
		// the reset): the cache of this process forgets this class's root.
		wp_upload_dir( null, false, true );
		self::wipe();
		self::empty_uploads();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
	}

	/* ------------------------------------------------------------ helpers */

	private static function admin(): int {
		return (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'];
	}

	private static function wipe(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		self::remove_tree( $root . '/ab-mcp-store', $root );
	}

	/** The uploads root of this class on a site (UPLOADS). */
	private static function uploads_root( string $site ): string {
		return AB_MCP_IT_Sites::docroot( $site ) . '/' . self::UPLOADS;
	}

	/** The uploads root of this class removed on both sites, with all it holds (only this class writes there). */
	private static function empty_uploads(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		foreach ( array( 'a', 'b' ) as $site ) {
			$uploads = self::uploads_root( $site );
			if ( is_dir( $uploads ) || is_link( $uploads ) ) {
				self::remove_tree( $uploads, $root );
			}
		}
	}

	/** The folder outside the uploads a case lets a link point to (a case that failed before its end leaves it). */
	private static function remove_outside(): void {
		$outside = AB_MCP_IT_Sites::docroot( 'a' ) . '/umweg-aussen';
		if ( is_dir( $outside ) ) {
			self::remove_tree( $outside, (string) AB_MCP_IT_Sites::state()['root'] );
		}
	}

	private static function remove_tree( string $path, string $root ): void {
		if ( 0 !== strpos( $path, $root . '/' ) ) {
			throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry, $root );
		}
		rmdir( $path );
	}

	/** Steps on site B. */
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

	/** The bytes of a JPEG of one colour, the same on both sites. */
	private static function jpeg( int $w, int $h, array $rgb ): string {
		$gd = imagecreatetruecolor( $w, $h );
		imagefilledrectangle( $gd, 0, 0, $w, $h, imagecolorallocate( $gd, ...$rgb ) );
		ob_start();
		imagejpeg( $gd, null, 90 );
		return (string) ob_get_clean();
	}

	/** An attachment of B, as the media library makes it: its id. */
	private static function upload_b( string $name, string $mime, string $bytes, array $args = array(), ?string $alt = null ): int {
		$step = array(
			'op'    => 'upload',
			'name'  => $name,
			'mime'  => $mime,
			'bytes' => base64_encode( $bytes ),
			'args'  => $args,
			'as'    => 'id',
		);
		if ( null !== $alt ) {
			$step['alt'] = $alt;
		}
		return (int) self::b( array( $step ) )['id'];
	}

	/** An attachment of A, in this process, as the media library makes it. */
	private static function upload_a( string $name, string $mime, string $bytes, array $args = array(), ?string $alt = null, string $time = '2026/10' ): int {
		$up = wp_upload_bits( $name, null, $bytes, $time );
		self::assertFalse( $up['error'] );
		$id = wp_insert_attachment(
			wp_slash(
				array_merge(
					array(
						'post_mime_type' => $mime,
						'post_title'     => pathinfo( $name, PATHINFO_FILENAME ),
						'post_status'    => 'inherit',
						'post_date'      => self::DATE,
						'post_date_gmt'  => self::DATE,
					),
					$args
				)
			),
			$up['file'],
			0,
			true
		);
		self::assertIsInt( $id );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		if ( null !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		}
		return $id;
	}

	/** A post of B. */
	private static function on_b( array $args ): int {
		return (int) self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => $args,
					'as'   => 'post',
				),
			)
		)['post'];
	}

	/** B's packages of before removed (an account keeps three open exports at most). */
	private static function clear_b(): void {
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
	}

	/** B packs its inventory and A takes it over: the package ids. */
	private static function staged(): array {
		self::clear_b();
		$ids    = array();
		$cursor = null;
		do {
			$step = array(
				'op'      => 'export',
				'purpose' => 'inventory',
				'as'      => 'package',
			);
			if ( null !== $cursor ) {
				$step['cursor'] = $cursor;
			}
			$package = self::b( array( $step ) )['package'];
			$cursor  = $package['cursor'];
			$ids[]   = self::import_package( $package, 'inventory', null );
		} while ( null !== $cursor && count( $ids ) < 50 );
		return $ids;
	}

	/** A package of B taken over here, chunk by chunk. */
	private static function import_package( array $package, string $purpose, ?string $report_hash ): string {
		$begin = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => $purpose,
		);
		if ( null !== $report_hash ) {
			$begin['report_hash'] = $report_hash;
		}
		$state = AB_MCP_Sync_Import::call( $begin );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
			$data  = self::b(
				array(
					array(
						'op'         => 'export_chunk',
						'package_id' => $package['package_id'],
						'chunk'      => $i,
						'as'         => 'c',
					),
				)
			)['c']['data'];
			$state = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $package['package_id'],
					'chunk'      => $i,
					'data'       => $data,
				)
			);
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		}
		self::assertTrue( $state['complete'] );
		return (string) $package['package_id'];
	}

	/** B packs the objects (or the media) of a report and A takes them over: the package id. */
	private static function packed( string $purpose, string $report_hash, array $refs ): string {
		$package = self::b(
			array(
				array(
					'op'          => 'export',
					'purpose'     => $purpose,
					'report_hash' => $report_hash,
					'refs'        => $refs,
					'as'          => 'package',
				),
			)
		)['package'];
		self::assertSame( $purpose, $package['purpose'] ?? null, (string) json_encode( $package ) );
		return self::import_package( $package, $purpose, $report_hash );
	}

	/** The definition of a tool of the staging group. */
	private static function def( string $name ): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r->get( $name );
	}

	/** A tool through the gate of the plugin, in this process. */
	private static function gated( string $name, array $a ) {
		return AB_MCP_REST_Controller::run_tool( $name, self::def( $name ), $a );
	}

	/** Every entry of these categories of a fresh proposal paired. */
	private static function paired( array $ids, array $categories = array( 'equal' ) ): void {
		$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'packages' => $ids ) );
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'categories'  => $categories,
		);
		$preview = self::gated( 'wp_site_pairs', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$done = self::gated( 'wp_site_pairs', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
	}

	/** wp_site_diff in this process, worked out to its end and paged: every entry. */
	private static function report( array $ids ): array {
		$answer = AB_MCP_Sync_Report::call( array( 'packages' => $ids ) );
		$calls  = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
			++$calls;
			self::assertLessThan( 300, $calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$all  = $answer['entries'];
		$page = $answer;
		while ( null !== $page['cursor'] ) {
			$page = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'], 'cursor' => $page['cursor'] ) );
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
		}
		$answer['all'] = $all;
		return $answer;
	}

	/** The entry of an object: a post by its slug ('post:about'), an attachment by its file ('attachment:2026/10/a.jpg'). */
	private static function entry( array $entries, string $name ): array {
		list( $kind, $key ) = explode( ':', $name, 2 );
		foreach ( $entries as $entry ) {
			foreach ( array( 'staging', 'live' ) as $side ) {
				$identity = $entry[ $side ]['identity'] ?? array();
				if ( $kind === $entry['kind'] && ( ( 'post' === $kind && ( $identity['post_name'] ?? null ) === $key ) || ( 'attachment' === $kind && ( $identity['file'] ?? null ) === $key ) ) ) {
					return $entry;
				}
			}
		}
		self::fail( "No entry for $name: " . json_encode( array_map( static fn( $e ) => array( $e['kind'], $e['result'], $e['staging']['identity'] ?? null ), $entries ) ) );
	}

	/** The report, the packages and the arguments of a plan for these entries. */
	private static function planned( array $names, array $extra = array() ): array {
		$report = self::report( self::staged() );
		$select = array();
		$refs   = array();
		$media  = array();
		foreach ( $names as $name ) {
			$entry    = self::entry( $report['all'], $name );
			$select[] = (int) $entry['n'];
			if ( isset( $entry['staging']['ref'] ) ) {
				$refs[] = array(
					'kind' => $entry['kind'],
					'ref'  => (string) $entry['staging']['ref'],
				);
				if ( 'attachment' === $entry['kind'] ) {
					$media[] = array(
						'kind' => 'attachment',
						'ref'  => (string) $entry['staging']['ref'],
					);
				}
			}
		}
		self::clear_b();
		$packages = array( self::packed( 'objects', $report['report_hash'], $refs ) );
		if ( array() !== $media ) {
			$packages[] = self::packed( 'media', $report['report_hash'], $media );
		}
		return array(
			'report' => $report,
			'args'   => array(
				'action'    => 'plan',
				'report_id' => $report['report_id'],
				'select'    => $select,
				'packages'  => $packages,
			) + $extra,
		);
	}

	/** A plan confirmed in this process: the run. */
	private static function begun( array $args ): array {
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertTrue( $preview['confirm_required'] );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		self::assertSame( 'open', $run['state'] );
		return $run;
	}

	/** Steps until the run is closed, at most $max. */
	private static function finish( int $run_id, int $max = 14 ): array {
		$answer = null;
		for ( $i = 0; $i < $max; $i++ ) {
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return $answer;
			}
		}
		self::fail( 'The run did not close within ' . $max . ' steps: ' . json_encode( $answer ) );
	}

	/** The status of a run, its steps with their journals. */
	private static function run_status( int $run_id ): array {
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertIsArray( $status, is_wp_error( $status ) ? $status->get_error_message() : '' );
		return $status;
	}

	/** The way back of a run, planned, confirmed and run to its close. */
	private static function back( int $run_id ): array {
		$args    = array(
			'action' => 'plan',
			'run_id' => $run_id,
		);
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$begun = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		$answer = null;
		for ( $i = 0; $i < 14; $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => (int) $begun['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => (int) $begun['run_id'] ) );
			}
		}
		self::fail( 'The way back did not close.' );
	}

	/** The write steps of a status. */
	private static function writes( array $status ): array {
		return array_values( array_filter( $status['steps'], static fn( $s ) => 'write' === $s['op'] ) );
	}

	/** The message of the check before the first write. */
	private static function check_message( array $status ): string {
		foreach ( $status['steps'] as $step ) {
			if ( 'check' === $step['op'] ) {
				return (string) json_encode( $step['result'] );
			}
		}
		return '';
	}

	/** Every file of the uploads of a site: path => SHA-256. */
	private static function uploads_of( string $site ): array {
		$root = self::uploads_root( $site );
		$out  = array();
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$out[ substr( (string) $file->getPathname(), strlen( $root ) + 1 ) ] = hash_file( 'sha256', (string) $file->getPathname() );
			}
		}
		ksort( $out );
		return $out;
	}

	/** The attachment of A with this file (_wp_attached_file), read from the rows. */
	private static function attachment_a( string $file ): int {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s", $file ) );
		self::assertNotNull( $id, "An attachment of A with the file $file." );
		return (int) $id;
	}

	/** A meta value of a post of A, read past every cache. */
	private static function meta_a( int $id, string $key ): array {
		return array_column( AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $id, 'meta_key' => $key ) ), 'meta_value' );
	}

	/** The row of a post of A, read past every cache. */
	private static function row_a( int $id ): array {
		$rows = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $id ) );
		self::assertCount( 1, $rows, "Post $id of A." );
		return $rows[0];
	}

	/** Filters that record every deletion of a post, an option or a file of the site. */
	private function watch_deletions(): void {
		add_filter(
			'pre_delete_post',
			function ( $check, $post ) {
				$this->deletions[] = array( 'delete_post', (int) ( is_object( $post ) ? $post->ID : $post ) );
				return $check;
			},
			1,
			2
		);
		add_filter(
			'pre_trash_post',
			function ( $check, $post ) {
				$this->deletions[] = array( 'trash_post', (int) ( is_object( $post ) ? $post->ID : $post ) );
				return $check;
			},
			1,
			2
		);
		add_filter(
			'wp_delete_file',
			function ( $file ) {
				$this->deletions[] = array( 'delete_file', (string) $file );
				return $file;
			},
			1,
			1
		);
		add_action(
			'delete_option',
			function ( $option ) {
				if ( ! AB_MCP_Sync_Exclusions::option( (string) $option ) ) {
					$this->deletions[] = array( 'delete_option', (string) $option );
				}
			},
			1,
			1
		);
	}

	/** What wp_delete_attachment_files() would delete of an attachment of A, relative to the uploads, sorted. */
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
		$base = (string) realpath( wp_get_upload_dir()['basedir'] );
		$out  = array();
		foreach ( $deleted as $file ) {
			$out[] = substr( (string) realpath( $file ), strlen( $base ) + 1 );
		}
		sort( $out );
		return $out;
	}

	/** The canonical form of an attachment of A, as the inventory reads it. */
	private static function canonical_a( int $id ): array {
		$kind = \AB_MCP_Sync_Db_Kinds::for_pair( 'attachment' );
		$read = $kind->canonical(
			array(
				'kind'   => 'attachment',
				'ref'    => (string) $id,
				'source' => \AB_MCP_Sync_Inventory::source( array( AB_MCP_IT_Sites::url( 'a' ), AB_MCP_IT_Sites::url( 'b' ) ) ),
			)
		);
		self::assertIsArray( $read );
		return $read;
	}

	/** The findings of the check before the first write of a run, by the staging ref of their object. */
	private static function findings( array $status ): array {
		$out = array();
		foreach ( $status['steps'] as $step ) {
			if ( 'check' === $step['op'] ) {
				foreach ( $step['result']['findings'] ?? array() as $finding ) {
					$out[ (string) ( $finding['staging_ref'] ?? '' ) ] = (string) $finding['message'];
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------- the normal case */

	public function testANewImageWithItsSizesAndANewPdfInANewPageGoOverAndTheWayBackLeavesThemWithTheirFiles(): void {
		self::pair();
		self::paired( self::staged() );
		$image = self::upload_b( 'garten.jpg', 'image/jpeg', self::jpeg( 1600, 1200, array( 40, 120, 60 ) ), array( 'post_title' => 'Garten', 'post_excerpt' => 'Der Garten im Herbst.', 'post_content' => 'Aufgenommen im Oktober.' ), 'Ein Garten mit Bäumen' );
		$pdf   = self::upload_b( 'flyer.pdf', 'application/pdf', self::PDF, array( 'post_title' => 'Flyer' ) );
		$url   = AB_MCP_IT_Sites::url( 'b' ) . '/' . self::UPLOADS . '/2026/10/';
		$page  = self::on_b(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_title'    => 'Galerie',
				'post_name'     => 'galerie',
				'post_author'   => 1,
				'post_date'     => self::DATE,
				'post_date_gmt' => self::DATE,
				'post_content'  => '<!-- wp:image {"id":' . $image . ',"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="' . $url . 'garten-1024x768.jpg" alt="Ein Garten mit Bäumen" class="wp-image-' . $image . '"/></figure>' . "\n" . '<!-- /wp:image -->' . "\n\n" . '<!-- wp:file {"id":' . $pdf . ',"href":"' . $url . 'flyer.pdf"} -->' . "\n" . '<div class="wp-block-file"><a href="' . $url . 'flyer.pdf">Flyer</a></div>' . "\n" . '<!-- /wp:file -->',
				'meta_input'    => array( '_thumbnail_id' => $image ),
			)
		);
		// The image hangs under the page, as the editor uploads it.
		self::b( array( array( 'op' => 'update_post', 'args' => array( 'ID' => $image, 'post_parent' => $page ) ) ) );
		$files_b = self::uploads_of( 'b' );
		self::assertGreaterThan( 4, count( $files_b ), 'The staging site made the sizes of the image: ' . json_encode( $files_b ) );
		self::assertSame( array(), self::uploads_of( 'a' ) );

		$planned = self::planned( array( 'attachment:2026/10/garten.jpg', 'attachment:2026/10/flyer.pdf', 'post:galerie' ) );
		foreach ( array( 'attachment:2026/10/garten.jpg', 'attachment:2026/10/flyer.pdf', 'post:galerie' ) as $name ) {
			self::assertSame( 'create', self::entry( $planned['report']['all'], $name )['action'], $name . ': ' . json_encode( self::entry( $planned['report']['all'], $name ) ) );
		}
		// The uploads root of this class, which setUp() removed: it is made as
		// the first file is laid (a site without an upload yet).
		self::assertDirectoryDoesNotExist( self::uploads_root( 'a' ) );
		// The file of a new attachment goes with its row, as an upload makes
		// it: a hook on add_attachment finds it there already.
		$at_insert = array();
		add_action(
			'add_attachment',
			static function ( $id ) use ( &$at_insert ) {
				$at_insert[] = (string) get_post_meta( (int) $id, '_wp_attached_file', true );
			}
		);
		$this->watch_deletions();
		$run = self::begun( $planned['args'] );
		// The check, then the first write: the image, made unattached; its
		// pair takes the staging base of that state, so a report sees it as
		// changed on staging until the parent step went through.
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		$first  = null;
		for ( $i = 0; $i < 4 && null === $first; $i++ ) {
			self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => (int) $run['run_id'] ) );
			$pair  = AB_MCP_Sync_Pairs::find_staging( $source, 'attachment', (string) $image );
			$first = is_array( $pair ) ? $pair : null;
		}
		self::assertIsArray( $first, 'The image was made by one of the first steps.' );
		self::assertSame( '0', self::row_a( (int) $first['live_ref'] )['post_parent'], 'Unattached until its parent step.' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $first['base_staging'] );
		$closed = self::finish( (int) $run['run_id'] );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( $status ) );
		self::assertNotSame( $first['base_staging'], AB_MCP_Sync_Pairs::find_staging( $source, 'attachment', (string) $image )['base_staging'], 'The staging base of the image without its parent until the parent step, with it after (the next report: equal).' );
		sort( $at_insert );
		self::assertSame( array( '2026/10/flyer.pdf', '2026/10/garten.jpg' ), $at_insert, 'Each new attachment had its file as its row was made.' );
		$writes = self::writes( $status );
		self::assertSame( array( 'attachment', 'attachment', 'post', 'attachment' ), array_map( static fn( $s ) => $s['parts']['object']['kind'], $writes ), 'The attachments first, the page, then the parent step of the image.' );
		foreach ( $writes as $step ) {
			self::assertSame( 'done', $step['state'], json_encode( $step ) );
			self::assertNull( $step['parts']['object']['deviated'] );
		}
		// Every file as on staging, laid by the run.
		self::assertSame( $files_b, self::uploads_of( 'a' ), 'Every file of both lists, byte for byte.' );
		$laid = array();
		foreach ( $writes as $step ) {
			foreach ( $step['result']['files'] ?? array() as $file ) {
				$laid[] = $file['path'];
			}
		}
		sort( $laid );
		self::assertSame( array_keys( $files_b ), $laid, 'Each file named as the run\'s own.' );
		$image_a = self::attachment_a( '2026/10/garten.jpg' );
		$pdf_a   = self::attachment_a( '2026/10/flyer.pdf' );
		$page_a  = get_page_by_path( 'galerie' );
		self::assertIsObject( $page_a );
		self::assertSame( (string) $page_a->ID, self::row_a( $image_a )['post_parent'], 'The image hangs under the new page.' );
		self::assertSame( 'Garten', self::row_a( $image_a )['post_title'] );
		self::assertSame( 'Der Garten im Herbst.', self::row_a( $image_a )['post_excerpt'] );
		self::assertSame( array( 'Ein Garten mit Bäumen' ), self::meta_a( $image_a, '_wp_attachment_image_alt' ) );
		self::assertSame( array( (string) $image_a ), self::meta_a( (int) $page_a->ID, '_thumbnail_id' ) );
		self::assertStringContainsString( '"id":' . $image_a, (string) $page_a->post_content );
		self::assertStringContainsString( 'wp-image-' . $image_a, (string) $page_a->post_content );
		self::assertStringContainsString( '"id":' . $pdf_a, (string) $page_a->post_content );
		self::assertStringContainsString( AB_MCP_IT_Sites::url( 'a' ) . '/' . self::UPLOADS . '/2026/10/flyer.pdf', (string) $page_a->post_content );
		self::assertNotEmpty( wp_get_attachment_image( $image_a, 'medium' ), 'WordPress shows the image here.' );
		$meta = wp_get_attachment_metadata( $image_a );
		self::assertIsArray( $meta );
		self::assertArrayHasKey( 'medium', $meta['sizes'] );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'attachment', (string) $image_a )['state'] );
		// The next report: equal, nothing to take over.
		$again = self::report( self::staged() );
		foreach ( array( 'attachment:2026/10/garten.jpg', 'attachment:2026/10/flyer.pdf', 'post:galerie' ) as $name ) {
			self::assertSame( 'equal', self::entry( $again['all'], $name )['result'], $name );
		}
		// The way back: the three new objects stay as rests, their files too.
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( $files_b, self::uploads_of( 'a' ), 'No file is deleted, not even one the run laid.' );
		$named = array();
		$rests = array();
		foreach ( $back['steps'] as $step ) {
			if ( isset( $step['result']['rest'] ) ) {
				$rests[] = $step['result']['rest']['kind'];
			}
			foreach ( $step['result']['files'] ?? array() as $file ) {
				$named[] = $file['path'];
			}
		}
		sort( $rests );
		sort( $named );
		self::assertSame( array( 'attachment', 'attachment', 'post' ), $rests );
		self::assertSame( array_keys( $files_b ), $named, 'The way back names every file the run laid.' );
		self::assertSame( '0', self::row_a( $image_a )['post_parent'], 'The parent step went back: the image hangs under no post.' );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( $source, 'attachment', (string) $image_a )['state'] );
		self::assertSame( array(), $this->deletions, 'Nothing was deleted, forward or back.' );
		// The file list of the form is what wp_delete_attachment_files()
		// would delete (decision 26, Befund R2-14): measured through its filter,
		// which here deletes nothing (after the watch, which would count it).
		remove_all_filters( 'wp_delete_file' );
		self::assertSame( self::core_list( $image_a ), array_column( self::canonical_a( $image_a )['form']['files'], 'path' ) );
		self::assertSame( $files_b, self::uploads_of( 'a' ), 'And still nothing deleted.' );
	}

	public function testAChangedTitleAltTextDescriptionAndParentOfAnAttachmentGoOverAndBackWithoutAFile(): void {
		// The logo both sites have gets a new title, alternative text and
		// description on staging and hangs under a new page there: the page
		// first, then the logo under it (the check before the first write
		// counts the page as one the run creates). Beside it on this site an
		// attachment whose name holds the logo's name: the prefilter of the
		// reverse map finds it, its list names none of the logo's files
		// (named_by(), byte for byte), so nothing is shared.
		self::pair();
		$bytes = self::jpeg( 800, 600, array( 200, 80, 40 ) );
		$a     = self::upload_a( 'logo.jpg', 'image/jpeg', $bytes, array( 'post_title' => 'Logo', 'post_content' => 'Das alte Logo.' ), 'Logo' );
		$b     = self::upload_b( 'logo.jpg', 'image/jpeg', $bytes, array( 'post_title' => 'Logo', 'post_content' => 'Das alte Logo.' ), 'Logo' );
		self::assertSame( self::uploads_of( 'a' ), self::uploads_of( 'b' ), 'The same files on both sites.' );
		self::upload_a( 'vereinslogo.jpg', 'image/jpeg', self::jpeg( 300, 200, array( 20, 80, 200 ) ) );
		self::paired( self::staged() );
		$seite = self::on_b( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Verein', 'post_name' => 'verein', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE, 'post_content' => 'Über den Verein.' ) );
		self::b(
			array(
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b, 'post_title' => 'Logo 2026', 'post_content' => 'Das neue Logo.', 'post_parent' => $seite ) ),
				array( 'op' => 'update_meta', 'post' => $b, 'key' => '_wp_attachment_image_alt', 'value' => 'Das Logo des Vereins' ),
			)
		);
		$row_before  = self::row_a( $a );
		$alt_before  = self::meta_a( $a, '_wp_attachment_image_alt' );
		$files       = self::uploads_of( 'a' );
		$planned     = self::planned( array( 'attachment:2026/10/logo.jpg', 'post:verein' ) );
		self::assertSame( 'take_over', self::entry( $planned['report']['all'], 'attachment:2026/10/logo.jpg' )['action'] );
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( $status ) );
		$writes = self::writes( $status );
		self::assertSame( array( 'post', 'attachment' ), array_map( static fn( $s ) => $s['parts']['object']['kind'], $writes ), 'The page first, then the logo under it.' );
		$step = $writes[1];
		self::assertSame( array( 'row', 'meta:_wp_attachment_image_alt' ), array_column( $step['parts']['parts'], 'part' ), 'The row and the alternative text, nothing else.' );
		self::assertSame( array( 'present' ), array_values( array_unique( array_column( $step['parts']['files'], 'state' ) ) ), 'Every file was there already.' );
		self::assertArrayNotHasKey( 'files', $step['result'], 'The run laid no file.' );
		self::assertSame( 'Logo 2026', self::row_a( $a )['post_title'] );
		self::assertSame( 'Das neue Logo.', self::row_a( $a )['post_content'] );
		self::assertSame( (string) get_page_by_path( 'verein' )->ID, self::row_a( $a )['post_parent'], 'The logo hangs under the new page.' );
		self::assertSame( array( 'Das Logo des Vereins' ), self::meta_a( $a, '_wp_attachment_image_alt' ) );
		self::assertSame( $files, self::uploads_of( 'a' ), 'No file changed.' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], 'The page stays as a rest: ' . json_encode( $back ) );
		$row_now = self::row_a( $a );
		unset( $row_now['post_modified'], $row_now['post_modified_gmt'], $row_before['post_modified'], $row_before['post_modified_gmt'] );
		self::assertSame( $row_before, $row_now, 'The row byte for byte as before.' );
		self::assertSame( $alt_before, self::meta_a( $a, '_wp_attachment_image_alt' ) );
		self::assertSame( $files, self::uploads_of( 'a' ) );
		self::assertSame( array(), $this->deletions );
	}

	public function testTheFirstFilesOfASiteWithoutMonthFoldersGoIntoItsUploadsAndOnlyWithTheRightToUploadAsTheyAreLaid(): void {
		// Codex round 1 of branch 10, B8: a production site without an upload
		// yet, the staging site without folders by month (the file bild.jpg,
		// no folder on its way): the uploads folder itself is made. A5: the
		// rights again right before a file is laid; an account that loses
		// upload_files as the files are provided (a filter of this site) lays
		// none, the step ends with the reason, and a run after that goes.
		self::pair();
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'update_option', 'name' => 'uploads_use_yearmonth_folders', 'value' => 0 ) ) );
		self::upload_b( 'flach.jpg', 'image/jpeg', self::jpeg( 400, 300, array( 60, 90, 120 ) ) );
		self::b( array( array( 'op' => 'update_option', 'name' => 'uploads_use_yearmonth_folders', 'value' => 1 ) ) );
		$files_b = self::uploads_of( 'b' );
		self::assertArrayHasKey( 'flach.jpg', $files_b, 'In the uploads themselves: ' . json_encode( $files_b ) );
		// The uploads root of this class, removed by setUp() and not made by
		// the staging site: this site has no uploads folder yet.
		self::assertDirectoryDoesNotExist( self::uploads_root( 'a' ) );
		$planned = self::planned( array( 'attachment:flach.jpg' ) );
		$revoked = false;
		add_filter(
			'wp_check_filetype_and_ext',
			function ( $answer ) use ( &$revoked ) {
				if ( ! $revoked ) {
					$revoked           = true;
					$this->caps_filter = static function ( $allcaps ) {
						$allcaps['upload_files'] = false;
						return $allcaps;
					};
					add_filter( 'user_has_cap', $this->caps_filter );
				}
				return $answer;
			}
		);
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertTrue( $revoked );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) . self::check_message( $status ) );
		$step = self::writes( $status )[0];
		self::assertSame( 'failed', $step['state'] );
		self::assertStringContainsString( 'may not upload files here any more', (string) $step['result']['message'] );
		self::assertArrayNotHasKey( 'files', $step['result'] );
		self::assertSame( array(), self::uploads_of( 'a' ), 'Nothing laid.' );
		remove_all_filters( 'wp_check_filetype_and_ext' );
		remove_filter( 'user_has_cap', $this->caps_filter );
		$this->caps_filter = null;
		// The same again, with the right: every file in the uploads folder,
		// which is made for the first of them.
		$planned = self::planned( array( 'attachment:flach.jpg' ) );
		$run     = self::begun( $planned['args'] );
		$closed  = self::finish( (int) $run['run_id'] );
		$status  = self::run_status( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( $status ) );
		self::assertSame( $files_b, self::uploads_of( 'a' ), 'Every file, byte for byte, in the uploads folder itself.' );
		self::assertSame( array( 'flach.jpg' ), self::meta_a( self::attachment_a( 'flach.jpg' ), '_wp_attached_file' ) );
		self::assertSame( array(), $this->deletions );
	}

	public function testAFileAHookChangesAsTheStepWritesOrPutsBackDeviatesTheAttachmentAndStopsItsWayBack(): void {
		// Codex round 1 of branch 10, A3: the state of an attachment read
		// after every call of the step's own and every part the way back puts
		// back holds its files (path, size, SHA-256), as its form does. Three
		// images both sites have, each with a new title and alternative text
		// on staging: a hook changes the file of the first as its row is
		// written (the object deviates, nothing of it goes back); another
		// hand changes a file of the second after the run (its way back stops
		// before any part); a hook changes a file of the third as its row is
		// put back (the alternative text stays). A fourth is edited on staging;
		// after the run another attachment of this site names one of its old
		// sizes: its three keys that name files stay together (Codex round 2,
		// A4), each with the reason. A fifth, edited as well: after the run
		// its backup sizes change on this site: another hand changed the
		// object, it stays whole (round 3, A4). A sixth, edited: the way
		// back runs for an account that may upload no JPEG (a filter), and it
		// goes back whole: the way back lays no file, it asks only whether the
		// content of each file it names again is what its name says (decision
		// of the lead on Codex round 2). Two images of September whose staging
		// sides change only their metadata: after the run another hand points
		// _wp_attached_file of the first to October, a key the journal does not
		// hold: the object is left whole (Codex round 3, A4); the second, which
		// no hand touched, goes back whole.
		self::pair();
		$ids = array();
		foreach ( array( 'erstes', 'zweites', 'drittes' ) as $k => $name ) {
			$bytes        = self::jpeg( 300, 200, array( 20 + $k, 40, 60 ) );
			$ids[ $name ] = array(
				'a' => self::upload_a( $name . '.jpg', 'image/jpeg', $bytes, array( 'post_title' => 'Alt' ), 'Alt' ),
				'b' => self::upload_b( $name . '.jpg', 'image/jpeg', $bytes, array( 'post_title' => 'Alt' ), 'Alt' ),
			);
		}
		$viertes_bytes = self::jpeg( 300, 200, array( 80, 80, 20 ) );
		$viertes       = array(
			'a' => self::upload_a( 'viertes.jpg', 'image/jpeg', $viertes_bytes ),
			'b' => self::upload_b( 'viertes.jpg', 'image/jpeg', $viertes_bytes ),
		);
		$fuenftes_bytes = self::jpeg( 300, 200, array( 20, 20, 80 ) );
		$fuenftes       = array(
			'a' => self::upload_a( 'fuenftes.jpg', 'image/jpeg', $fuenftes_bytes ),
			'b' => self::upload_b( 'fuenftes.jpg', 'image/jpeg', $fuenftes_bytes ),
		);
		$sechstes_bytes = self::jpeg( 300, 200, array( 60, 20, 60 ) );
		$sechstes       = array(
			'a' => self::upload_a( 'sechstes.jpg', 'image/jpeg', $sechstes_bytes ),
			'b' => self::upload_b( 'sechstes.jpg', 'image/jpeg', $sechstes_bytes ),
		);
		$herbst = array();
		foreach ( array( 'herbst', 'winter' ) as $k => $name ) {
			$bytes_sep       = self::jpeg( 300, 200, array( 90, 50 + $k, 10 ) );
			$herbst[ $name ] = array(
				'a' => self::upload_a( $name . '.jpg', 'image/jpeg', $bytes_sep, array(), null, '2026/09' ),
				'b' => (int) self::b( array( array( 'op' => 'upload', 'name' => $name . '.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( $bytes_sep ), 'time' => '2026/09', 'as' => 'id' ) ) )['id'],
			);
		}
		self::paired( self::staged() );
		foreach ( $herbst as $name => $id ) {
			$m                          = wp_get_attachment_metadata( $id['a'] );
			$m['image_meta']['caption'] = ucfirst( $name ) . ' im Garten';
			self::b( array( array( 'op' => 'update_meta', 'post' => $id['b'], 'key' => '_wp_attachment_metadata', 'value' => $m ) ) );
		}
		self::b(
			array(
				array( 'op' => 'edit_image', 'post' => $viertes['b'], 'suffix' => '1700000001', 'image' => array( 'w' => 200, 'h' => 300, 'rgb' => array( 20, 80, 80 ) ) ),
				array( 'op' => 'edit_image', 'post' => $fuenftes['b'], 'suffix' => '1700000002', 'image' => array( 'w' => 200, 'h' => 300, 'rgb' => array( 80, 20, 20 ) ) ),
				array( 'op' => 'edit_image', 'post' => $sechstes['b'], 'suffix' => '1700000003', 'image' => array( 'w' => 200, 'h' => 300, 'rgb' => array( 20, 60, 20 ) ) ),
			)
		);
		foreach ( $ids as $name => $id ) {
			self::b(
				array(
					array( 'op' => 'update_post', 'args' => array( 'ID' => $id['b'], 'post_title' => 'Neu' ) ),
					array( 'op' => 'update_meta', 'post' => $id['b'], 'key' => '_wp_attachment_image_alt', 'value' => 'Neu' ),
				)
			);
		}
		$planned = self::planned( array( 'attachment:2026/10/erstes.jpg', 'attachment:2026/10/zweites.jpg', 'attachment:2026/10/drittes.jpg', 'attachment:2026/10/viertes.jpg', 'attachment:2026/10/fuenftes.jpg', 'attachment:2026/10/sechstes.jpg', 'attachment:2026/09/herbst.jpg', 'attachment:2026/09/winter.jpg' ) );
		$touch   = null;
		add_action(
			'edit_attachment',
			static function ( $id ) use ( &$touch ) {
				if ( null !== $touch && (int) $id === $touch ) {
					file_put_contents( get_attached_file( (int) $id ), 'x', FILE_APPEND );
					$touch = null;
				}
			}
		);
		$this->watch_deletions();
		$touch  = $ids['erstes']['a'];
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertNull( $touch, 'The hook ran.' );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) . self::check_message( $status ) );
		$by = array();
		foreach ( self::writes( $status ) as $step ) {
			$by[ (int) $step['parts']['object']['live_ref'] ] = $step;
		}
		self::assertSame( 'unclear', $by[ $ids['erstes']['a'] ]['state'] );
		self::assertStringContainsString( 'changed the file 2026/10/erstes.jpg of the attachment', (string) $by[ $ids['erstes']['a'] ]['result']['message'] );
		self::assertSame( 'done', $by[ $ids['zweites']['a'] ]['state'] );
		self::assertSame( 'done', $by[ $ids['drittes']['a'] ]['state'] );
		self::assertSame( 'done', $by[ $viertes['a'] ]['state'] );
		self::assertSame( 'done', $by[ $fuenftes['a'] ]['state'] );
		self::assertSame( 'done', $by[ $sechstes['a'] ]['state'] );
		foreach ( $herbst as $name => $id ) {
			self::assertSame( 'done', $by[ $id['a'] ]['state'], $name );
			self::assertContains( 'meta:_wp_attachment_metadata', array_column( $by[ $id['a'] ]['parts']['parts'], 'part' ), $name );
			self::assertNotContains( 'meta:_wp_attached_file', array_column( $by[ $id['a'] ]['parts']['parts'], 'part' ), $name . ': its file is no part of the journal.' );
		}
		// Another hand changes a file of the second; a hook the third as its row goes back.
		file_put_contents( get_attached_file( $ids['zweites']['a'] ), 'y', FILE_APPEND );
		$fremd                  = self::upload_a( 'fremd.jpg', 'image/jpeg', self::jpeg( 120, 90, array( 1, 2, 3 ) ) );
		$fremd_meta             = wp_get_attachment_metadata( $fremd );
		$fremd_meta['sizes']['geteilt'] = array( 'file' => 'viertes-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' );
		wp_update_attachment_metadata( $fremd, $fremd_meta );
		self::assertFileExists( wp_get_upload_dir()['basedir'] . '/2026/10/viertes-150x150.jpg', 'An old size of the fourth.' );
		// Another hand points the first of September to a copy in October.
		$basedir = wp_get_upload_dir()['basedir'];
		wp_mkdir_p( $basedir . '/2026/10' );
		copy( $basedir . '/2026/09/herbst.jpg', $basedir . '/2026/10/herbst.jpg' );
		update_post_meta( $herbst['herbst']['a'], '_wp_attached_file', '2026/10/herbst.jpg' );
		$sizes_after                      = get_post_meta( $fuenftes['a'], '_wp_attachment_backup_sizes', true );
		$sizes_after['full-orig']['note'] = 'changed on this site after the run';
		update_post_meta( $fuenftes['a'], '_wp_attachment_backup_sizes', $sizes_after );
		$touch = $ids['drittes']['a'];
		add_filter(
			'upload_mimes',
			static function ( $mimes ) {
				unset( $mimes['jpg|jpeg|jpe'] );
				return $mimes;
			}
		);
		$back  = self::back( (int) $run['run_id'] );
		self::assertNull( $touch, 'The hook ran on the way back.' );
		$steps = array();
		foreach ( $back['steps'] as $one ) {
			if ( isset( $one['result']['live_ref'] ) ) {
				$steps[ (int) $one['result']['live_ref'] ] = $one;
			}
		}
		foreach ( array( 'erstes', 'zweites' ) as $name ) {
			self::assertSame( 'Neu', self::row_a( $ids[ $name ]['a'] )['post_title'], "$name: nothing goes back." );
		}
		self::assertSame( array( 'Alt' ), self::meta_a( $ids['erstes']['a'], '_wp_attachment_image_alt' ), 'The first stopped at its row: its alternative text was never written.' );
		self::assertSame( array( 'Neu' ), self::meta_a( $ids['zweites']['a'], '_wp_attachment_image_alt' ) );
		self::assertStringContainsString( 'the file 2026/10/zweites.jpg of the attachment changed since the step read it last', (string) json_encode( $steps[ $ids['zweites']['a'] ]['result'], JSON_UNESCAPED_SLASHES ) );
		self::assertSame( 'Alt', self::row_a( $ids['drittes']['a'] )['post_title'], 'The row of the third went back before its file changed.' );
		self::assertSame( array( 'Neu' ), self::meta_a( $ids['drittes']['a'], '_wp_attachment_image_alt' ), 'Then the way back stopped for it.' );
		self::assertStringContainsString( 'changed the file 2026/10/drittes.jpg of the attachment', (string) json_encode( $steps[ $ids['drittes']['a'] ]['result'], JSON_UNESCAPED_SLASHES ) );
		$viertes_parts = array_column( $steps[ $viertes['a'] ]['result']['parts'], null, 'part' );
		foreach ( array( 'meta:_wp_attached_file', 'meta:_wp_attachment_metadata', 'meta:_wp_attachment_backup_sizes' ) as $key ) {
			self::assertSame( 'left', $viertes_parts[ $key ]['state'] ?? null, $key . ': ' . json_encode( $viertes_parts ) );
			self::assertStringContainsString( 'the attachment ' . $fremd . ' of this site names it as well', (string) $viertes_parts[ $key ]['note'], $key );
		}
		self::assertSame( array( '2026/10/viertes-e1700000001.jpg' ), self::meta_a( $viertes['a'], '_wp_attached_file' ), 'The fourth keeps the files of its edit.' );
		$fuenftes_parts = array_column( $steps[ $fuenftes['a'] ]['result']['parts'], null, 'part' );
		foreach ( array( 'meta:_wp_attached_file', 'meta:_wp_attachment_metadata' ) as $key ) {
			self::assertSame( 'left', $fuenftes_parts[ $key ]['state'] ?? null, $key . ': ' . json_encode( $fuenftes_parts ) );
			self::assertStringContainsString( 'the part meta:_wp_attachment_backup_sizes, which names files of the attachment, changed since the step left it', (string) $fuenftes_parts[ $key ]['note'], $key );
		}
		self::assertSame( 'left', $fuenftes_parts['meta:_wp_attachment_backup_sizes']['state'] );
		self::assertSame( array( '2026/10/fuenftes-e1700000002.jpg' ), self::meta_a( $fuenftes['a'], '_wp_attached_file' ), 'The fifth keeps the files of its edit, all three keys together.' );
		$sechstes_parts = array_column( $steps[ $sechstes['a'] ]['result']['parts'], null, 'part' );
		foreach ( array( 'meta:_wp_attached_file', 'meta:_wp_attachment_metadata', 'meta:_wp_attachment_backup_sizes' ) as $key ) {
			self::assertSame( 'restored', $sechstes_parts[ $key ]['state'] ?? null, $key . ': ' . json_encode( $sechstes_parts ) );
		}
		self::assertSame( array( '2026/10/sechstes.jpg' ), self::meta_a( $sechstes['a'], '_wp_attached_file' ), 'The sixth back whole, for an account that may upload no JPEG.' );
		self::assertSame( 'done', $steps[ $sechstes['a'] ]['state'] );
		$herbst_parts = array_column( $steps[ $herbst['herbst']['a'] ]['result']['parts'], null, 'part' );
		self::assertSame( 'left', $herbst_parts['meta:_wp_attachment_metadata']['state'], json_encode( $herbst_parts ) );
		self::assertStringContainsString( 'the part meta:_wp_attached_file, which names files of the attachment, changed since the step left it', (string) $herbst_parts['meta:_wp_attachment_metadata']['note'] );
		self::assertSame( 'Herbst im Garten', wp_get_attachment_metadata( $herbst['herbst']['a'] )['image_meta']['caption'] ?? null, 'Its metadata stays as the run wrote it.' );
		$winter_parts = array_column( $steps[ $herbst['winter']['a'] ]['result']['parts'], null, 'part' );
		self::assertSame( 'restored', $winter_parts['meta:_wp_attachment_metadata']['state'], json_encode( $winter_parts ) );
		self::assertSame( 'done', $steps[ $herbst['winter']['a'] ]['state'], 'An ordinary way back of the metadata alone: whole.' );
		self::assertSame( '', wp_get_attachment_metadata( $herbst['winter']['a'] )['image_meta']['caption'] ?? null );
		unset( $steps[ $sechstes['a'] ], $steps[ $herbst['winter']['a'] ] );
		foreach ( $steps as $one ) {
			self::assertSame( 'unclear', $one['state'] );
		}
		self::assertSame( array(), $this->deletions );
	}

	public function testAWebpSizeAndAnImportOfOldWithAbsolutePathsLockNoAttachmentBesideThem(): void {
		// Codex round 2 of branch 10, the two B: a change of the title and the
		// alternative text of a JPEG both sites have, with a size of WebP the
		// media library made, goes there and back for an account that may not
		// upload WebP (a file the step does not write is asked only whether
		// its content is what its name says). A new image 2026/11/bild.jpg goes
		// beside an import of old on this site, bild.jpg of January, whose
		// _wp_attached_file and metadata.file are absolute paths under the
		// uploads and which has a backup size: its list read as the core reads
		// it names none of the new paths. And an SVG both sites have, a type a
		// plugin registers through upload_mimes for this account (no
		// unfiltered_upload): its title and alternative text go there and
		// back as well (Codex round 3, B: the check of a file that lies there
		// asked the types WordPress knows without the ones upload_mimes adds).
		self::pair();
		$svg       = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12"><circle cx="6" cy="6" r="5" fill="blue"/></svg>' . "\n";
		$svg_dir   = wp_get_upload_dir()['basedir'] . '/2026/10';
		wp_mkdir_p( $svg_dir );
		file_put_contents( $svg_dir . '/symbol.svg', $svg );
		$symbol_a = wp_insert_attachment( wp_slash( array( 'post_mime_type' => 'image/svg+xml', 'post_title' => 'symbol', 'post_status' => 'inherit', 'post_date' => self::DATE, 'post_date_gmt' => self::DATE ) ), $svg_dir . '/symbol.svg', 0, true );
		self::assertIsInt( $symbol_a );
		$symbol_b = (int) self::b( array( array( 'op' => 'upload', 'name' => 'symbol.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( self::jpeg( 12, 12, array( 0, 0, 200 ) ) ), 'generate' => false, 'as' => 'id' ) ) )['id'];
		self::b(
			array(
				array( 'op' => 'put_file', 'path' => '2026/10/symbol.svg', 'bytes' => base64_encode( $svg ) ),
				array( 'op' => 'update_meta', 'post' => $symbol_b, 'key' => '_wp_attached_file', 'value' => '2026/10/symbol.svg' ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $symbol_b, 'post_mime_type' => 'image/svg+xml' ) ),
			)
		);
		unlink( self::uploads_root( 'b' ) . '/2026/10/symbol.jpg' );
		$bytes = self::jpeg( 400, 300, array( 150, 100, 50 ) );
		$a     = self::upload_a( 'foto.jpg', 'image/jpeg', $bytes, array( 'post_title' => 'Foto' ), 'Foto' );
		$b     = self::upload_b( 'foto.jpg', 'image/jpeg', $bytes, array( 'post_title' => 'Foto' ), 'Foto' );
		$gd    = imagecreatetruecolor( 100, 75 );
		ob_start();
		imagewebp( $gd );
		$webp                  = (string) ob_get_clean();
		$uploads               = wp_get_upload_dir()['basedir'];
		$meta                  = wp_get_attachment_metadata( $a );
		$meta['sizes']['webp'] = array( 'file' => 'foto-100x75.webp', 'width' => 100, 'height' => 75, 'mime-type' => 'image/webp' );
		file_put_contents( $uploads . '/2026/10/foto-100x75.webp', $webp );
		wp_update_attachment_metadata( $a, $meta );
		self::b(
			array(
				array( 'op' => 'put_file', 'path' => '2026/10/foto-100x75.webp', 'bytes' => base64_encode( $webp ) ),
				array( 'op' => 'update_meta', 'post' => $b, 'key' => '_wp_attachment_metadata', 'value' => $meta ),
			)
		);
		self::assertSame( self::uploads_of( 'a' ), self::uploads_of( 'b' ), 'The same files on both sites.' );
		add_filter(
			'upload_mimes',
			static function ( $mimes ) {
				$mimes['svg'] = 'image/svg+xml';
				return $mimes;
			}
		);
		// The import of old on this site.
		$old = wp_upload_bits( 'bild.jpg', null, self::jpeg( 320, 240, array( 10, 20, 30 ) ), '2026/01' );
		self::assertFalse( $old['error'] );
		$alt = wp_insert_attachment( wp_slash( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Altbestand', 'post_name' => 'altbestand-januar', 'post_status' => 'inherit', 'post_date' => self::DATE, 'post_date_gmt' => self::DATE ) ), $old['file'], 0, true );
		self::assertIsInt( $alt );
		$old_meta         = wp_generate_attachment_metadata( $alt, $old['file'] );
		$old_meta['file'] = $old['file'];
		wp_update_attachment_metadata( $alt, $old_meta );
		update_post_meta( $alt, '_wp_attached_file', $old['file'] );
		copy( $old['file'], $uploads . '/2026/01/bild-orig.jpg' );
		update_post_meta( $alt, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'width' => 320, 'height' => 240, 'file' => 'bild-orig.jpg' ) ) );
		self::assertStringStartsWith( '/', (string) get_post_meta( $alt, '_wp_attached_file', true ) );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b, 'post_title' => 'Foto 2026' ) ),
				array( 'op' => 'update_meta', 'post' => $b, 'key' => '_wp_attachment_image_alt', 'value' => 'Ein Foto' ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $symbol_b, 'post_title' => 'Symbol 2026' ) ),
				array( 'op' => 'update_meta', 'post' => $symbol_b, 'key' => '_wp_attachment_image_alt', 'value' => 'Ein Symbol' ),
				array( 'op' => 'upload', 'name' => 'bild.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( self::jpeg( 300, 200, array( 200, 10, 10 ) ) ), 'time' => '2026/11', 'as' => 'id' ),
			)
		);
		$row_before = self::row_a( $a );
		$svg_before = self::row_a( $symbol_a );
		add_filter(
			'upload_mimes',
			static function ( $mimes ) {
				unset( $mimes['webp'] );
				return $mimes;
			}
		);
		$planned = self::planned( array( 'attachment:2026/10/foto.jpg', 'attachment:2026/11/bild.jpg', 'attachment:2026/10/symbol.svg' ) );
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( $status ) );
		self::assertSame( 'Foto 2026', self::row_a( $a )['post_title'] );
		self::assertSame( array( 'Ein Foto' ), self::meta_a( $a, '_wp_attachment_image_alt' ) );
		self::assertSame( 'Symbol 2026', self::row_a( $symbol_a )['post_title'] );
		self::assertSame( array( 'Ein Symbol' ), self::meta_a( $symbol_a, '_wp_attachment_image_alt' ) );
		self::assertFileExists( $uploads . '/2026/11/bild.jpg', 'The new image beside the import of old.' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], 'The new image stays as a rest: ' . json_encode( $back ) );
		$row_now = self::row_a( $a );
		unset( $row_now['post_modified'], $row_now['post_modified_gmt'], $row_before['post_modified'], $row_before['post_modified_gmt'] );
		self::assertSame( $row_before, $row_now, 'The row of the JPEG back byte for byte.' );
		self::assertSame( array( 'Foto' ), self::meta_a( $a, '_wp_attachment_image_alt' ) );
		$svg_now = self::row_a( $symbol_a );
		unset( $svg_now['post_modified'], $svg_now['post_modified_gmt'], $svg_before['post_modified'], $svg_before['post_modified_gmt'] );
		self::assertSame( $svg_before, $svg_now, 'The row of the SVG back byte for byte.' );
		self::assertSame( array(), self::meta_a( $symbol_a, '_wp_attachment_image_alt' ), 'Its alternative text, there not before, gone again.' );
		self::assertSame( $webp, file_get_contents( $uploads . '/2026/10/foto-100x75.webp' ) );
		self::assertSame( array(), $this->deletions );
	}

	/* ----------------------------------------------- what locks, before */

	public function testWhatWouldReplaceShareOrLinkAFileOrIsOfATypeThisAccountMayNotUploadLocksBeforeAnythingIsWritten(): void {
		// Decision 26, clause by clause: another file at the path (never
		// written over, also not one the run would call its own), a path
		// another attachment of this site names, a link on the way, a type
		// this account may not upload by its name; each found by the check
		// before the first write, which closes the run with nothing written.
		// A file listed but missing on staging, and two attachments of the
		// plan naming one path, are locked before: the report and the plan.
		self::pair();
		self::paired( self::staged() );
		$ersetzt = self::upload_b( 'ersetzt.jpg', 'image/jpeg', self::jpeg( 300, 200, array( 10, 20, 30 ) ), array(), null );
		$geteilt = self::upload_b( 'geteilt.jpg', 'image/jpeg', self::jpeg( 300, 200, array( 30, 20, 10 ) ), array(), null );
		$link    = (int) self::b( array( array( 'op' => 'upload', 'name' => 'verlinkt.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( self::jpeg( 300, 200, array( 1, 2, 3 ) ) ), 'time' => '2026/09', 'as' => 'id' ) ) )['id'];
		$pdf     = self::upload_b( 'preise.pdf', 'application/pdf', self::PDF );
		$fehlt   = self::upload_b( 'fehlt.jpg', 'image/jpeg', self::jpeg( 400, 300, array( 9, 9, 9 ) ) );
		// A size of fehlt.jpg gone on staging: its list names a missing file.
		$sizes = (array) self::b( array( array( 'op' => 'files_of', 'post' => $fehlt, 'as' => 'list' ) ) )['list']['files'];
		$small = '';
		foreach ( $sizes as $file ) {
			if ( false !== strpos( $file['path'], '150x150' ) ) {
				$small = $file['path'];
			}
		}
		self::assertNotSame( '', $small );
		unlink( self::uploads_root( 'b' ) . '/' . $small );
		// An image under a page of staging the plan leaves out; an image whose
		// attachment says PNG while its file is a JPEG by its name.
		$seite = self::on_b( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Seite', 'post_name' => 'seite', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE, 'post_content' => 'x' ) );
		$waise = self::upload_b( 'waise.jpg', 'image/jpeg', self::jpeg( 200, 100, array( 4, 4, 4 ) ), array( 'post_parent' => $seite ) );
		$typ   = self::upload_b( 'typ.jpg', 'image/png', self::jpeg( 200, 100, array( 8, 8, 8 ) ) );
		// A PNG named .jpg whose bytes this site has already at its path (a
		// file left over): present, but by its content of another type than
		// the attachment says, as the way back asks it of the file it names.
		$gd = imagecreatetruecolor( 50, 50 );
		ob_start();
		imagepng( $gd );
		$png  = (string) ob_get_clean();
		$hier = self::upload_b( 'hier.jpg', 'image/jpeg', $png, array(), null );
		// A JPEG whose thumbnail this site has left over byte for byte, a PNG
		// named .jpg (Codex round 1, C: every file of the list by its content,
		// also one that lies there already).
		$rest       = self::upload_b( 'rest.jpg', 'image/jpeg', self::jpeg( 300, 200, array( 7, 77, 7 ) ), array(), null );
		$rest_thumb = '';
		foreach ( (array) self::b( array( array( 'op' => 'files_of', 'post' => $rest, 'as' => 'list' ) ) )['list']['files'] as $file ) {
			if ( false !== strpos( $file['path'], '150x150' ) ) {
				$rest_thumb = $file['path'];
			}
		}
		self::assertNotSame( '', $rest_thumb );
		self::b( array( array( 'op' => 'put_file', 'path' => $rest_thumb, 'bytes' => base64_encode( $png ) ) ) );
		// Two attachments of the plan naming one file (the second's thumb is
		// the first's file).
		$zwilling_a = self::upload_b( 'zwilling.jpg', 'image/jpeg', self::jpeg( 120, 90, array( 5, 5, 5 ) ), array(), null );
		$zwilling_b = self::upload_b( 'zwilling-zwei.jpg', 'image/jpeg', self::jpeg( 120, 90, array( 6, 6, 6 ) ), array(), null );
		self::b( array( array( 'op' => 'update_meta', 'post' => $zwilling_b, 'key' => '_wp_attachment_metadata', 'value' => array( 'file' => '2026/10/zwilling-zwei.jpg', 'width' => 120, 'height' => 90, 'thumb' => 'zwilling.jpg', 'sizes' => array() ) ) ) );
		unset( $zwilling_a );
		// Codex round 1, A6: an image this site has already, byte for byte, but
		// under an attachment that keeps its file as an absolute path in the
		// uploads (an import of old): the reverse map reads it as the core
		// does, so the path is shared. And an image whose name another
		// attachment of this site holds in a name that is no plain path (its
		// list does not read whole): fail closed.
		$absolut_bytes = self::jpeg( 160, 120, array( 33, 66, 99 ) );
		$absolut       = self::upload_b( 'absolut.jpg', 'image/jpeg', $absolut_bytes, array(), null );
		$unklar        = self::upload_b( 'unklar.jpg', 'image/jpeg', self::jpeg( 160, 120, array( 99, 66, 33 ) ), array(), null );
		// A new attachment on an SVG that lies on this site byte for byte but
		// belongs to no attachment here (left over): WordPress knows SVG (a
		// plugin registers it), this account may not upload it. Only a file
		// the list of the same attachment here names is asked against every
		// known type; this one counts as a file the step lays (the precision
		// of the lead on Codex round 2).
		$svg    = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>' . "\n";
		$grafik = (int) self::b( array( array( 'op' => 'upload', 'name' => 'grafik.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( self::jpeg( 10, 10, array( 1, 1, 1 ) ) ), 'generate' => false, 'as' => 'id' ) ) )['id'];
		self::b(
			array(
				array( 'op' => 'put_file', 'path' => '2026/10/grafik.svg', 'bytes' => base64_encode( $svg ) ),
				array( 'op' => 'update_meta', 'post' => $grafik, 'key' => '_wp_attached_file', 'value' => '2026/10/grafik.svg' ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $grafik, 'post_mime_type' => 'image/svg+xml' ) ),
			)
		);
		// And a new JPEG whose list names such an SVG beside it (a size of its
		// own name, left over here): as a file the step lays.
		$mitwaise = (int) self::b( array( array( 'op' => 'upload', 'name' => 'mitwaise.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( self::jpeg( 40, 40, array( 4, 40, 4 ) ) ), 'generate' => false, 'as' => 'id' ) ) )['id'];
		self::b(
			array(
				array( 'op' => 'put_file', 'path' => '2026/10/mitwaise-symbol.svg', 'bytes' => base64_encode( $svg ) ),
				array( 'op' => 'update_meta', 'post' => $mitwaise, 'key' => '_wp_attachment_metadata', 'value' => array( 'file' => '2026/10/mitwaise.jpg', 'width' => 40, 'height' => 40, 'sizes' => array( 'symbol' => array( 'file' => 'mitwaise-symbol.svg', 'width' => 10, 'height' => 10, 'mime-type' => 'image/svg+xml' ) ) ) ),
			)
		);
		// An image whose list names a size that is a PHP script by its name
		// (the main file a JPEG as the attachment says).
		$skript = self::upload_b( 'skript.jpg', 'image/jpeg', self::jpeg( 120, 90, array( 7, 7, 7 ) ), array(), null );
		self::b(
			array(
				array( 'op' => 'put_file', 'path' => '2026/10/skript-klein.php', 'bytes' => base64_encode( "<?php\n" ) ),
				array( 'op' => 'update_meta', 'post' => $skript, 'key' => '_wp_attachment_metadata', 'value' => array( 'file' => '2026/10/skript.jpg', 'width' => 120, 'height' => 90, 'sizes' => array( 'klein' => array( 'file' => 'skript-klein.php', 'width' => 60, 'height' => 45, 'mime-type' => 'image/jpeg' ) ) ) ),
			)
		);
		// On A: another file where ersetzt.jpg goes; an attachment whose list
		// names geteilt.jpg; the folder of September a link; no PDF allowed.
		$uploads = self::uploads_root( 'a' );
		wp_mkdir_p( $uploads . '/2026/10' );
		file_put_contents( $uploads . '/2026/10/ersetzt.jpg', 'another file of this site' );
		file_put_contents( $uploads . '/2026/10/hier.jpg', $png );
		file_put_contents( $uploads . '/' . $rest_thumb, $png );
		file_put_contents( $uploads . '/2026/10/grafik.svg', $svg );
		file_put_contents( $uploads . '/2026/10/mitwaise-symbol.svg', $svg );
		add_filter(
			'mime_types',
			static function ( $types ) {
				$types['svg'] = 'image/svg+xml';
				return $types;
			}
		);
		add_filter(
			'upload_mimes',
			static function ( $mimes ) {
				unset( $mimes['svg'] );
				return $mimes;
			}
		);
		$other = self::upload_a( 'anders.jpg', 'image/jpeg', self::jpeg( 300, 200, array( 99, 99, 99 ) ) );
		$meta  = wp_get_attachment_metadata( $other );
		$meta['sizes']['geteilt'] = array( 'file' => 'geteilt.jpg', 'width' => 300, 'height' => 200, 'mime-type' => 'image/jpeg' );
		wp_update_attachment_metadata( $other, $meta );
		$alt = self::upload_a( 'absolut.jpg', 'image/jpeg', $absolut_bytes, array( 'post_name' => 'altbestand' ) );
		update_post_meta( $alt, '_wp_attached_file', wp_get_upload_dir()['basedir'] . '/2026/10/absolut.jpg' );
		$quer = self::upload_a( 'unklar-alt.jpg', 'image/jpeg', self::jpeg( 100, 100, array( 1, 9, 1 ) ) );
		$meta = wp_get_attachment_metadata( $quer );
		$meta['sizes']['quer'] = array( 'file' => '../unklar.jpg', 'width' => 100, 'height' => 100, 'mime-type' => 'image/jpeg' );
		wp_update_attachment_metadata( $quer, $meta );
		wp_mkdir_p( AB_MCP_IT_Sites::docroot( 'a' ) . '/elsewhere' );
		symlink( AB_MCP_IT_Sites::docroot( 'a' ) . '/elsewhere', $uploads . '/2026/09' );
		add_filter(
			'upload_mimes',
			static function ( $mimes ) {
				unset( $mimes['pdf'] );
				return $mimes;
			}
		);
		$files_before = self::uploads_of( 'a' );
		$planned      = self::planned( array( 'attachment:2026/10/ersetzt.jpg', 'attachment:2026/10/geteilt.jpg', 'attachment:2026/09/verlinkt.jpg', 'attachment:2026/10/preise.pdf', 'attachment:2026/10/zwilling.jpg', 'attachment:2026/10/zwilling-zwei.jpg', 'attachment:2026/10/waise.jpg', 'attachment:2026/10/typ.jpg', 'attachment:2026/10/hier.jpg', 'attachment:2026/10/skript.jpg', 'attachment:2026/10/absolut.jpg', 'attachment:2026/10/unklar.jpg', 'attachment:2026/10/rest.jpg', 'attachment:2026/10/grafik.svg', 'attachment:2026/10/mitwaise.jpg' ) );
		$missing      = self::entry( $planned['report']['all'], 'attachment:2026/10/fehlt.jpg' );
		self::assertSame( 'locked', $missing['action'] );
		self::assertContains( 'attachment:missing_file', array_column( $missing['locks'], 'key' ) );
		$this->watch_deletions();
		$preview = self::gated( 'wp_site_push', $planned['args'] );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$shared = array_values( array_filter( $preview['preview']['locked'], static fn( $l ) => false !== strpos( (string) $l['reason'], 'attachment:shared_path' ) ) );
		self::assertCount( 2, $shared, 'Both attachments naming one path: ' . json_encode( $preview['preview']['locked'] ) );
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $closed['state'] );
		$found = self::findings( self::run_status( (int) $run['run_id'] ) );
		self::assertStringContainsString( 'attachment:replace_file', $found[ (string) $ersetzt ] ?? '', json_encode( $found ) );
		self::assertStringContainsString( 'attachment:shared_path', $found[ (string) $geteilt ] ?? '', json_encode( $found ) );
		self::assertStringContainsString( 'attachment:unsafe_path', $found[ (string) $link ] ?? '', json_encode( $found ) );
		self::assertStringContainsString( 'attachment:file_type', $found[ (string) $pdf ] ?? '', json_encode( $found ) );
		self::assertStringContainsString( 'post:reference_unmapped', $found[ (string) $waise ] ?? '', 'Its page is not in the plan: ' . json_encode( $found ) );
		self::assertStringContainsString( 'attachment:file_type', $found[ (string) $typ ] ?? '', 'The main file of another type than the attachment says: ' . json_encode( $found ) );
		self::assertStringContainsString( 'by its content of another type', $found[ (string) $hier ] ?? '', 'A file this site has, by its content: ' . json_encode( $found ) );
		self::assertStringContainsString( 'skript-klein.php: a type this account may not upload', $found[ (string) $skript ] ?? '', 'A file of the list besides the main one, by its name: ' . json_encode( $found ) );
		self::assertStringContainsString( 'the attachment ' . $alt . ' of this site names it as well', $found[ (string) $absolut ] ?? '', 'Its absolute path read as the core reads it: ' . json_encode( $found ) );
		self::assertStringContainsString( 'could not be read whole', $found[ (string) $unklar ] ?? '', 'A list of a candidate that does not read whole: ' . json_encode( $found ) );
		self::assertStringContainsString( 'file ' . $rest_thumb . ' of this site the attachment would name is by its content of another type', $found[ (string) $rest ] ?? '', 'A size left over, by its content: ' . json_encode( $found, JSON_UNESCAPED_SLASHES ) );
		self::assertStringContainsString( 'attachment:file_type', $found[ (string) $grafik ] ?? '', 'A left-over SVG no attachment here names, for an account that may not upload SVG: ' . json_encode( $found, JSON_UNESCAPED_SLASHES ) );
		self::assertStringContainsString( 'grafik.svg is of no type this account may upload by its name', $found[ (string) $grafik ] ?? '' );
		self::assertStringContainsString( 'mitwaise-symbol.svg of this site the attachment would name', $found[ (string) $mitwaise ] ?? '', json_encode( $found, JSON_UNESCAPED_SLASHES ) );
		self::assertStringContainsString( 'it counts as a file the step lays', $found[ (string) $mitwaise ] ?? '' );
		// The same plan without the package of media: every file to lay is
		// missing; a package of media of another report is refused by the plan.
		$args             = $planned['args'];
		$args['packages'] = array( $planned['args']['packages'][0] );
		$run              = self::begun( $args );
		self::assertSame( 'closed_aborted', self::finish( (int) $run['run_id'] )['state'] );
		$found = self::findings( self::run_status( (int) $run['run_id'] ) );
		self::assertStringContainsString( 'packages of media of this run hold no file', $found[ (string) $typ ] ?? '', json_encode( $found ) );
		self::clear_b();
		$other            = self::packed( 'media', str_repeat( 'e', 64 ), array( array( 'kind' => 'attachment', 'ref' => (string) $typ ) ) );
		$args['packages'] = array( $planned['args']['packages'][0], $other );
		$refused          = self::gated( 'wp_site_push', $args );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_objects_report', $refused->get_error_code(), $refused->get_error_message() );
		self::assertSame( $files_before, self::uploads_of( 'a' ), 'Nothing was laid, nothing written over.' );
		self::assertSame( 'another file of this site', file_get_contents( $uploads . '/2026/10/ersetzt.jpg' ) );
		$made = array_map( 'basename', array_map( 'get_attached_file', get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ) ) ) );
		sort( $made );
		self::assertSame( array( 'absolut.jpg', 'anders.jpg', 'unklar-alt.jpg' ), $made, 'No attachment was made.' );
		self::assertSame( array(), $this->deletions );
		unlink( $uploads . '/2026/09' );
		rmdir( AB_MCP_IT_Sites::docroot( 'a' ) . '/elsewhere' );
	}

	/* ----------------------------------------------- a package of media */

	public function testAPackageOfMediaWhoseFilesDoNotReadWholeIsNotUsed(): void {
		// What the production site checks once every chunk is there
		// (AB_MCP_Sync_Media_Package::unpacked()): the pieces of a file in
		// their order, each file against its SHA-256, a file once, the lines
		// as many as the manifest declares; a package that does not hold is
		// removed with the way. One that holds is indexed.
		self::pair();
		$bytes = random_bytes( 100 );
		$piece = static fn( string $path, string $data, int $piece = 0, int $pieces = 1, ?string $sha = null, ?int $size = null ): array => array(
			'data'   => base64_encode( $data ),
			'kind'   => 'file',
			'path'   => $path,
			'piece'  => $piece,
			'pieces' => $pieces,
			'ref'    => '12',
			'sha256' => $sha ?? hash( 'sha256', $data ),
			'size'   => $size ?? strlen( $data ),
		);
		$big   = random_bytes( \AB_MCP_Sync_Media_Package::PIECE + 5 );
		$parts = array( substr( $big, 0, \AB_MCP_Sync_Media_Package::PIECE ), substr( $big, \AB_MCP_Sync_Media_Package::PIECE ) );
		$cases = array(
			// A name that is no UTF-8 (caf\xE9.jpg, as Latin-1 stores it): in
			// the lines and the index as the marker of its bytes (Codex round
			// 1, C), read back as them.
			'whole'              => array( array( $piece( "2026/10/caf\xE9.jpg", $bytes ) ), null ),
			'another hash'       => array( array( $piece( '2026/10/a.jpg', $bytes, 0, 1, hash( 'sha256', 'x' ) ) ), 'ab_mcp_sync_import_lines' ),
			'a file twice'       => array( array( $piece( '2026/10/a.jpg', $bytes ), $piece( '2026/10/a.jpg', $bytes ) ), 'ab_mcp_sync_import_lines' ),
			'pieces out of order' => array( array( $piece( '2026/10/b.bin', $parts[1], 1, 2, hash( 'sha256', $big ), strlen( $big ) ), $piece( '2026/10/b.bin', $parts[0], 0, 2, hash( 'sha256', $big ), strlen( $big ) ) ), 'ab_mcp_sync_import_lines' ),
			'ends within a file' => array( array( $piece( '2026/10/b.bin', $parts[0], 0, 2, hash( 'sha256', $big ), strlen( $big ) ) ), 'ab_mcp_sync_import_lines' ),
			'a line of an object' => array( array( array( 'identity' => array(), 'kind' => 'post', 'locks' => array(), 'ref' => '7', 'status' => 'z8' ) ), 'ab_mcp_sync_import_lines' ),
		);
		$n = 0;
		foreach ( $cases as $what => list( $lines, $code ) ) {
			$id   = sprintf( '%032x', 0xa110 + ++$n );
			$text = '';
			foreach ( $lines as $line ) {
				$text .= \AB_MCP_Sync_Canon::encode( $line ) . "\n";
			}
			$chunk  = (string) gzencode( $text, 6 );
			$signed = self::b(
				array(
					array(
						'op'      => 'sign_manifest',
						'as'      => 'signed',
						'package' => array(
							'package_id'  => $id,
							'purpose'     => 'media',
							'report_hash' => str_repeat( 'c', 64 ),
							'kinds'       => array( array( 'complete' => false, 'kind' => 'attachment', 'subtype' => 'attachment' ) ),
							'objects'     => count( $lines ),
							'unpacked'    => strlen( $text ),
							'chunks'      => array( array( 'bytes' => strlen( $chunk ), 'sha256' => hash( 'sha256', $chunk ) ) ),
							'bytes'       => strlen( $chunk ),
							'sha256'      => hash( 'sha256', $chunk ),
						),
					),
				)
			)['signed'];
			$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'manifest' => $signed, 'purpose' => 'media', 'report_hash' => str_repeat( 'c', 64 ) ) );
			self::assertIsArray( $state, $what );
			$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 0, 'data' => base64_encode( $chunk ) ) );
			if ( null === $code ) {
				self::assertIsArray( $state, $what . ': ' . ( is_wp_error( $state ) ? $state->get_error_message() : '' ) );
				self::assertTrue( $state['complete'], $what );
				$taken = AB_MCP_Sync_Import::taken( $id, 'media' );
				self::assertIsArray( $taken, $what );
				self::assertSame( array( array( 'chunks' => array( 0 ), 'path' => "2026/10/caf\xE9.jpg", 'pieces' => 1, 'ref' => '12', 'sha256' => hash( 'sha256', $bytes ), 'size' => 100 ) ), $taken['files'], 'The index of the files, its name byte for byte.' );
				$to = (string) tempnam( sys_get_temp_dir(), 'abit' );
				self::assertTrue( \AB_MCP_Sync_Media_Package::extract( $taken, $taken['files'][0], $to ) );
				self::assertSame( $bytes, file_get_contents( $to ), 'A file read out of its chunks byte for byte.' );
				// A file whose index names a piece more than its lines hold does
				// not read whole (its bytes and its hash as they are).
				$longer           = $taken['files'][0];
				$longer['pieces'] = 2;
				$unread           = \AB_MCP_Sync_Media_Package::extract( $taken, $longer, $to );
				self::assertInstanceOf( \WP_Error::class, $unread );
				self::assertSame( 'ab_mcp_sync_media_unread', $unread->get_error_code() );
				unlink( $to );
				self::assertSame( 'ab_mcp_sync_import_purpose_held', AB_MCP_Sync_Import::taken( $id, 'objects' )->get_error_code(), 'A package of media is no package of objects.' );
				continue;
			}
			self::assertInstanceOf( \WP_Error::class, $state, $what );
			self::assertSame( $code, $state->get_error_code(), $what . ': ' . $state->get_error_message() );
		}
		// The staging site packs the files of an attachment only where its
		// paths pass the check of credentials its values pass (Codex round 1,
		// A7): a file stored as password=….jpg leaves it out, withheld.
		$bytes_b = self::jpeg( 80, 60, array( 5, 50, 5 ) );
		$offen   = (int) self::b( array( array( 'op' => 'upload', 'name' => 'offen.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( $bytes_b ), 'generate' => false, 'as' => 'id' ) ) )['id'];
		$geheim  = (int) self::b( array( array( 'op' => 'upload', 'name' => 'geheim.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( $bytes_b ), 'generate' => false, 'as' => 'id' ) ) )['id'];
		self::b(
			array(
				array( 'op' => 'put_file', 'path' => '2026/10/password=synthetic-review-value.jpg', 'bytes' => base64_encode( $bytes_b ) ),
				array( 'op' => 'update_meta', 'post' => $geheim, 'key' => '_wp_attached_file', 'value' => '2026/10/password=synthetic-review-value.jpg' ),
			)
		);
		self::clear_b();
		$media = self::b( array( array( 'op' => 'export', 'purpose' => 'media', 'report_hash' => str_repeat( 'c', 64 ), 'refs' => array( array( 'kind' => 'attachment', 'ref' => (string) $offen ), array( 'kind' => 'attachment', 'ref' => (string) $geheim ) ), 'as' => 'package' ) ) )['package'];
		self::assertSame( array( (string) $offen ), $media['media']['packed'], (string) json_encode( $media ) );
		self::assertSame( array( array( 'kind' => 'attachment', 'needs' => 'credential', 'ref' => (string) $geheim, 'subtype' => 'attachment' ) ), $media['withheld']['objects'] );
		self::assertSame( 1, $media['credentials']['held'] + $media['credentials']['names'] );
		self::clear_b();
		// Codex round 1, C: a package holds at most as many lines as a manifest
		// names objects (a piece a line); the next attachment goes to left, as
		// for the bytes, the package is made. Two attachments of 2502 empty
		// files each (a list of many sizes): 5004 lines, the second left.
		$viele = array();
		foreach ( array( 'viele-a', 'viele-b' ) as $name ) {
			$id    = (int) self::b( array( array( 'op' => 'upload', 'name' => $name . '.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( $bytes_b ), 'generate' => false, 'as' => 'id' ) ) )['id'];
			$steps = array();
			$sizes = array();
			for ( $k = 0; $k < 2501; $k++ ) {
				$file              = sprintf( '%s-%04d.jpg', $name, $k );
				$steps[]           = array( 'op' => 'put_file', 'path' => '2026/10/' . $file, 'bytes' => '' );
				$sizes[ 's' . $k ] = array( 'file' => $file, 'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg' );
			}
			$steps[] = array( 'op' => 'update_meta', 'post' => $id, 'key' => '_wp_attachment_metadata', 'value' => array( 'file' => '2026/10/' . $name . '.jpg', 'width' => 80, 'height' => 60, 'sizes' => $sizes ) );
			self::b( $steps );
			$viele[] = (string) $id;
		}
		$many = self::b( array( array( 'op' => 'export', 'purpose' => 'media', 'report_hash' => str_repeat( 'c', 64 ), 'refs' => array( array( 'kind' => 'attachment', 'ref' => $viele[0] ), array( 'kind' => 'attachment', 'ref' => $viele[1] ) ), 'as' => 'package' ) ) )['package'];
		self::assertSame( array( $viele[0] ), $many['media']['packed'], substr( (string) json_encode( $many ), 0, 600 ) );
		self::assertSame( array( $viele[1] ), $many['media']['left'] );
		self::assertSame( 2502, $many['objects'] );
		self::clear_b();

	}

	/* ------------------------------------------- the files of one step */

	public function testAPathTakenAsTheFilesAreLaidKeepsTheFilesLaidBeforeAsTheRunsOwnAndMakesNoAttachment(): void {
		// «Alle Dateien eines Attachments werden erst vollständig
		// bereitgestellt, dann gemeinsam unter ihre Namen gelegt (exklusiv);
		// scheitert eine, bleiben die schon gelegten als eigene Reste im
		// Journal und das Attachment wird nicht angelegt.» Every file of the
		// image is provided first (each read from the package of media and
		// checked by its content); as the third is checked, another hand puts
		// a file where it goes: the first two are laid, the third is not
		// (fopen 'xb': nothing written over), and the attachment is not made.
		// A second attachment of the run, a PNG named .jpg, is refused by its
		// content before any of its files is laid; so is a third, a JPEG
		// whose thumbnail is a PNG named .jpg. A fourth: another hand swaps
		// its folder for a link as its file is opened (Codex round 1, A1):
		// the file made through the link stays empty and unclear, named.
		self::pair();
		self::paired( self::staged() );
		self::upload_b( 'drei.jpg', 'image/jpeg', self::jpeg( 1600, 1200, array( 70, 70, 140 ) ) );
		$gd = imagecreatetruecolor( 60, 40 );
		ob_start();
		imagepng( $gd );
		$png = (string) ob_get_clean();
		self::upload_b( 'falsch.jpg', 'image/jpeg', $png, array(), null );
		// A JPEG whose thumbnail on staging is a PNG by its content, named
		// .jpg: a file of the list besides the main one.
		$klein = self::upload_b( 'klein.jpg', 'image/jpeg', self::jpeg( 300, 200, array( 90, 30, 30 ) ), array(), null );
		$thumb = '';
		foreach ( (array) self::b( array( array( 'op' => 'files_of', 'post' => $klein, 'as' => 'list' ) ) )['list']['files'] as $file ) {
			if ( false !== strpos( $file['path'], '150x150' ) ) {
				$thumb = $file['path'];
			}
		}
		self::assertNotSame( '', $thumb );
		self::b( array( array( 'op' => 'put_file', 'path' => $thumb, 'bytes' => base64_encode( $png ) ) ) );
		self::b( array( array( 'op' => 'upload', 'name' => 'umweg.jpg', 'mime' => 'image/jpeg', 'bytes' => base64_encode( self::jpeg( 40, 30, array( 3, 3, 3 ) ) ), 'time' => '2026/08', 'generate' => false, 'as' => 'id' ) ) );
		$files_b = self::uploads_of( 'b' );
		$planned = self::planned( array( 'attachment:2026/10/drei.jpg', 'attachment:2026/10/falsch.jpg', 'attachment:2026/10/klein.jpg', 'attachment:2026/08/umweg.jpg' ) );
		$uploads = self::uploads_root( 'a' );
		$outside = AB_MCP_IT_Sites::docroot( 'a' ) . '/umweg-aussen';
		mkdir( $outside );
		\AB_MCP_Sync_Attachment_Writer::$probe = static function ( $full, $when ) use ( $uploads, $outside ) {
			if ( 'open' === $when && $uploads . '/2026/08/umweg.jpg' === $full ) {
				rename( $uploads . '/2026/08', $uploads . '/2026/08-alt' );
				symlink( $outside, $uploads . '/2026/08' );
			}
		};
		$checked = 0;
		$taken   = null;
		add_filter(
			'wp_check_filetype_and_ext',
			static function ( $answer, $file, $filename ) use ( &$checked, &$taken, $uploads ) {
				if ( 0 === strpos( (string) $filename, 'drei' ) && 3 === ++$checked ) {
					$taken = '2026/10/' . $filename;
					wp_mkdir_p( $uploads . '/2026/10' );
					file_put_contents( $uploads . '/' . $taken, 'put here by another hand' );
				}
				return $answer;
			},
			10,
			3
		);
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) . self::check_message( $status ) );
		self::assertNotNull( $taken );
		$writes = self::writes( $status );
		$states = array_column( $writes, 'state' );
		sort( $states );
		self::assertSame( array( 'failed', 'failed', 'failed', 'unclear' ), $states );
		$of = static function ( string $path ) use ( $writes ): array {
			foreach ( $writes as $step ) {
				if ( in_array( $path, array_column( $step['parts']['files'] ?? array(), 'path' ), true ) ) {
					return $step;
				}
			}
			self::fail( "No step of $path." );
		};
		$drei   = $of( '2026/10/drei.jpg' );
		$falsch = $of( '2026/10/falsch.jpg' );
		$klein  = $of( '2026/10/klein.jpg' );
		$umweg  = $of( '2026/08/umweg.jpg' );
		$own    = array_column( $drei['result']['files'] ?? array(), 'path' );
		self::assertCount( 2, $own, 'Two files laid before the third: the run\'s own, named. ' . json_encode( $drei ) );
		foreach ( $own as $path ) {
			self::assertSame( $files_b[ $path ], hash_file( 'sha256', $uploads . '/' . $path ), 'Laid byte for byte.' );
		}
		self::assertSame( 'put here by another hand', file_get_contents( $uploads . '/' . $taken ), 'Never written over.' );
		$states = array_column( $drei['parts']['files'], 'state', 'path' );
		self::assertSame( 'failed', $states[ $taken ] );
		self::assertNull( $drei['result']['live_ref'], 'No attachment was made.' );
		self::assertStringContainsString( 'attachment:file_type', (string) $falsch['result']['message'] );
		self::assertArrayNotHasKey( 'files', $falsch['result'], 'Nothing of the PNG was laid.' );
		self::assertFileDoesNotExist( $uploads . '/2026/10/falsch.jpg' );
		self::assertStringContainsString( 'its content is of another type than its name says', (string) $klein['result']['message'], 'The thumbnail, by its content.' );
		self::assertArrayNotHasKey( 'files', $klein['result'], 'Nothing of the JPEG with the PNG thumbnail was laid.' );
		self::assertFileDoesNotExist( $uploads . '/2026/10/klein.jpg' );
		self::assertFileDoesNotExist( $uploads . '/' . $thumb );
		self::assertSame( 'unclear', $umweg['state'] );
		self::assertSame( array( '2026/08/umweg.jpg' ), array_column( $umweg['result']['files_unclear'] ?? array(), 'path' ), json_encode( $umweg['result'] ) );
		self::assertArrayNotHasKey( 'files', $umweg['result'], 'Not the run\'s own.' );
		self::assertSame( '', file_get_contents( $outside . '/umweg.jpg' ), 'Made through the link, nothing written into it.' );
		self::assertFileDoesNotExist( $uploads . '/2026/08-alt/umweg.jpg' );
		global $wpdb;
		self::assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ), 'No attachment of either.' );
		// No step made a row: there is no way back (each step names its
		// files in its own result, above).
		$none = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => (int) $run['run_id'] ) );
		self::assertInstanceOf( \WP_Error::class, $none );
		self::assertSame( 'ab_mcp_sync_revert_nothing', $none->get_error_code() );
		self::assertSame( array(), $this->deletions );
	}

	/* ----------------------------------- an edited image, the rights back */

	public function testAnImageEditedOnStagingLaysItsNewFilesOnlyWithTheRightToUploadAndTheWayBackAsksTheTypeOfTheFileItNamesAgain(): void {
		// The image both sites have is edited on staging (wp_save_image():
		// a new file beside it, new sizes, the original in the backup sizes).
		// Forward the keys that name files need upload_files; the new files
		// are laid, the original stays where it is (present), the old sizes
		// stay on disk untouched. On the way back every check of the forward
		// way holds for every file the backed-up values name, and the three
		// keys that name files go back only together (Codex round 2, A4):
		// an old size whose content is not what its name says (a PNG named
		// .jpg, on both sites since before the run) keeps all three with their
		// values before. A filter of that check that changes a file of the
		// attachment stops the way back for it before any further part goes
		// back (Codex round 2, A3): the alternative text, changed on staging
		// as well, stays.
		self::pair();
		$bytes = self::jpeg( 800, 600, array( 120, 30, 30 ) );
		$a     = self::upload_a( 'bild.jpg', 'image/jpeg', $bytes );
		$b     = self::upload_b( 'bild.jpg', 'image/jpeg', $bytes );
		$gd    = imagecreatetruecolor( 150, 150 );
		ob_start();
		imagepng( $gd );
		$verkleidet = (string) ob_get_clean();
		file_put_contents( wp_get_upload_dir()['basedir'] . '/2026/10/bild-150x150.jpg', $verkleidet );
		self::b( array( array( 'op' => 'put_file', 'path' => '2026/10/bild-150x150.jpg', 'bytes' => base64_encode( $verkleidet ) ) ) );
		$old   = self::uploads_of( 'a' );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'edit_image', 'post' => $b, 'suffix' => '1700000000', 'image' => array( 'w' => 600, 'h' => 800, 'rgb' => array( 30, 30, 120 ) ) ),
				array( 'op' => 'update_meta', 'post' => $b, 'key' => '_wp_attachment_image_alt', 'value' => 'Bearbeitet' ),
			)
		);
		$neu     = self::upload_b( 'neu.jpg', 'image/jpeg', self::jpeg( 100, 100, array( 1, 1, 1 ) ) );
		$planned = self::planned( array( 'attachment:2026/10/bild.jpg', 'attachment:2026/10/neu.jpg' ) );
		self::assertSame( 'take_over', self::entry( $planned['report']['all'], 'attachment:2026/10/bild.jpg' )['action'] );
		$no_upload = static function ( $allcaps ) {
			$allcaps['upload_files'] = false;
			return $allcaps;
		};
		$this->caps_filter = $no_upload;
		add_filter( 'user_has_cap', $no_upload );
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $closed['state'] );
		$found = self::findings( self::run_status( (int) $run['run_id'] ) );
		self::assertStringContainsString( 'change the files of the attachment', $found[ (string) $b ] ?? '', json_encode( $found ) );
		self::assertStringContainsString( 'upload a file (upload_files)', $found[ (string) $neu ] ?? '', json_encode( $found ) );
		remove_filter( 'user_has_cap', $no_upload );
		$this->watch_deletions();
		$args           = $planned['args'];
		$args['select'] = array( (int) self::entry( $planned['report']['all'], 'attachment:2026/10/bild.jpg' )['n'] );
		$run            = self::begun( $args );
		$closed = self::finish( (int) $run['run_id'] );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( $status ) );
		$step  = self::writes( $status )[0];
		$state = array_column( $step['parts']['files'], 'state', 'path' );
		self::assertSame( 'present', $state['2026/10/bild.jpg'], 'The original, kept as the edit\'s backup, is there already.' );
		self::assertSame( 'laid', $state['2026/10/bild-e1700000000.jpg'] );
		self::assertSame( array( '2026/10/bild-e1700000000.jpg' ), self::meta_a( $a, '_wp_attached_file' ) );
		$now = self::uploads_of( 'a' );
		foreach ( $old as $path => $sha ) {
			self::assertSame( $sha, $now[ $path ] ?? null, "The old file $path stays as it was." );
		}
		// The way back (Codex rounds 1 and 2, A4 and A3): the old size that is
		// a PNG named .jpg refuses the three keys together, and the filter of
		// that check changes another old size of the attachment as it runs.
		$uploads = wp_get_upload_dir()['basedir'];
		$changed = false;
		add_filter(
			'wp_check_filetype_and_ext',
			static function ( $answer ) use ( $uploads, &$changed ) {
				if ( ! $changed ) {
					$changed = true;
					file_put_contents( $uploads . '/2026/10/bild-300x225.jpg', 'z', FILE_APPEND );
				}
				return $answer;
			}
		);
		$now   = self::uploads_of( 'a' );
		$back  = self::back( (int) $run['run_id'] );
		$parts = array();
		foreach ( $back['steps'] as $one ) {
			foreach ( $one['result']['parts'] ?? array() as $part ) {
				$parts[ $part['part'] ] = $part;
			}
		}
		$named = array();
		foreach ( $back['steps'] as $one ) {
			foreach ( $one['result']['files'] ?? array() as $file ) {
				$named[] = $file['path'];
			}
		}
		self::assertContains( '2026/10/bild-e1700000000.jpg', $named, 'The way back names the files the run laid; they stay.' );
		// The keys in their order: the first two refused together; the
		// metadata comes after the alternative text, when the way back has
		// stopped already.
		foreach ( array( 'meta:_wp_attached_file', 'meta:_wp_attachment_backup_sizes' ) as $key ) {
			self::assertSame( 'left', $parts[ $key ]['state'], $key . ': ' . json_encode( $parts ) );
			self::assertStringContainsString( 'go back only together', $parts[ $key ]['note'], $key );
			self::assertStringContainsString( 'name the file 2026/10/bild-150x150.jpg again (its content is not what its name says', $parts[ $key ]['note'], $key );
		}
		self::assertSame( 'left', $parts['meta:_wp_attachment_metadata']['state'] );
		self::assertStringContainsString( 'The way back stopped for this object', $parts['meta:_wp_attachment_metadata']['note'] );
		self::assertSame( 'left', $parts['meta:_wp_attachment_image_alt']['state'], json_encode( $parts ) );
		self::assertStringContainsString( 'changed before the part meta:_wp_attachment_image_alt went back', $parts['meta:_wp_attachment_image_alt']['note'] );
		self::assertSame( array( 'Bearbeitet' ), self::meta_a( $a, '_wp_attachment_image_alt' ), 'The alternative text stays: the way back stopped for the object.' );
		self::assertSame( array( '2026/10/bild-e1700000000.jpg' ), self::meta_a( $a, '_wp_attached_file' ), 'Left with its value before named.' );
		self::assertSame( array_keys( $now ), array_keys( self::uploads_of( 'a' ) ), 'No file is deleted on the way back, not even one the run laid.' );
		self::assertSame( array(), $this->deletions );
	}
}
