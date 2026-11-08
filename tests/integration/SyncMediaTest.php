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
 *   another attachment names, a link on the way, a file of a type the
 *   account may not upload by its content; a path taken between the check
 *   and the laying refuses the step, the files laid before stay as the
 *   run's own, no attachment is made. No step deletes anything.
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

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
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
		AB_MCP_Sync_Resources::$probe = null;
		AB_MCP_Sync_Resources::$time  = null;
		delete_option( \AB_MCP_Change_Lock::OPTION );
		remove_all_filters( 'pre_delete_post' );
		remove_all_filters( 'pre_trash_post' );
		remove_all_filters( 'wp_delete_file' );
		remove_all_filters( 'upload_mimes' );
		remove_all_actions( 'delete_option' );
		remove_all_actions( 'add_attachment' );
		remove_all_filters( 'wp_check_filetype_and_ext' );
		remove_all_filters( 'user_has_cap' );
		self::empty_uploads();
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
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

	/** The uploads of both sites emptied (each test makes its own files). */
	private static function empty_uploads(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		foreach ( array( 'a', 'b' ) as $site ) {
			$uploads = AB_MCP_IT_Sites::docroot( $site ) . '/wp-content/uploads';
			$entries = is_dir( $uploads ) ? array_diff( (array) scandir( $uploads ), array( '.', '..' ) ) : array();
			foreach ( $entries as $entry ) {
				self::remove_tree( $uploads . '/' . $entry, $root );
			}
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
	private static function upload_a( string $name, string $mime, string $bytes, array $args = array(), ?string $alt = null ): int {
		$up = wp_upload_bits( $name, null, $bytes, '2026/10' );
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
		$root = AB_MCP_IT_Sites::docroot( $site ) . '/wp-content/uploads';
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
		$url   = AB_MCP_IT_Sites::url( 'b' ) . '/wp-content/uploads/2026/10/';
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
		// A site without an upload yet: its uploads folder is made as the
		// first file is laid.
		rmdir( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads' );
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
		self::assertStringContainsString( AB_MCP_IT_Sites::url( 'a' ) . '/wp-content/uploads/2026/10/flyer.pdf', (string) $page_a->post_content );
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
		unlink( AB_MCP_IT_Sites::docroot( 'b' ) . '/wp-content/uploads/' . $small );
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
		// Two attachments of the plan naming one file (the second's thumb is
		// the first's file).
		$zwilling_a = self::upload_b( 'zwilling.jpg', 'image/jpeg', self::jpeg( 120, 90, array( 5, 5, 5 ) ), array(), null );
		$zwilling_b = self::upload_b( 'zwilling-zwei.jpg', 'image/jpeg', self::jpeg( 120, 90, array( 6, 6, 6 ) ), array(), null );
		self::b( array( array( 'op' => 'update_meta', 'post' => $zwilling_b, 'key' => '_wp_attachment_metadata', 'value' => array( 'file' => '2026/10/zwilling-zwei.jpg', 'width' => 120, 'height' => 90, 'thumb' => 'zwilling.jpg', 'sizes' => array() ) ) ) );
		unset( $zwilling_a );
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
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		wp_mkdir_p( $uploads . '/2026/10' );
		file_put_contents( $uploads . '/2026/10/ersetzt.jpg', 'another file of this site' );
		file_put_contents( $uploads . '/2026/10/hier.jpg', $png );
		$other = self::upload_a( 'anders.jpg', 'image/jpeg', self::jpeg( 300, 200, array( 99, 99, 99 ) ) );
		$meta  = wp_get_attachment_metadata( $other );
		$meta['sizes']['geteilt'] = array( 'file' => 'geteilt.jpg', 'width' => 300, 'height' => 200, 'mime-type' => 'image/jpeg' );
		wp_update_attachment_metadata( $other, $meta );
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
		$planned      = self::planned( array( 'attachment:2026/10/ersetzt.jpg', 'attachment:2026/10/geteilt.jpg', 'attachment:2026/09/verlinkt.jpg', 'attachment:2026/10/preise.pdf', 'attachment:2026/10/zwilling.jpg', 'attachment:2026/10/zwilling-zwei.jpg', 'attachment:2026/10/waise.jpg', 'attachment:2026/10/typ.jpg', 'attachment:2026/10/hier.jpg', 'attachment:2026/10/skript.jpg' ) );
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
		self::assertSame( array( 'anders.jpg' ), array_map( 'basename', array_map( 'get_attached_file', get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ) ) ) ), 'No attachment was made.' );
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
			'whole'              => array( array( $piece( '2026/10/a.jpg', $bytes ) ), null ),
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
				self::assertSame( array( array( 'chunks' => array( 0 ), 'path' => '2026/10/a.jpg', 'pieces' => 1, 'ref' => '12', 'sha256' => hash( 'sha256', $bytes ), 'size' => 100 ) ), $taken['files'], 'The index of the files.' );
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
		// whose thumbnail is a PNG named .jpg.
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
		$files_b = self::uploads_of( 'b' );
		$planned = self::planned( array( 'attachment:2026/10/drei.jpg', 'attachment:2026/10/falsch.jpg', 'attachment:2026/10/klein.jpg' ) );
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
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
		self::assertSame( array( 'failed', 'failed', 'failed' ), array_column( $writes, 'state' ) );
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
		global $wpdb;
		self::assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ), 'No attachment of either.' );
		self::assertSame( array(), $this->deletions );
	}

	/* ----------------------------------- an edited image, the rights back */

	public function testAnImageEditedOnStagingLaysItsNewFilesOnlyWithTheRightToUploadAndTheWayBackAsksTheTypeOfTheFileItNamesAgain(): void {
		// The image both sites have is edited on staging (wp_save_image():
		// a new file beside it, new sizes, the original in the backup sizes).
		// Forward the keys that name files need upload_files; the new files
		// are laid, the original stays where it is (present), the old sizes
		// stay on disk untouched. On the way back every check of the forward
		// way holds: the file the backed-up _wp_attached_file names has to be
		// of a type this account may upload by its content; where it is not,
		// that part stays with its value before, the others go back.
		self::pair();
		$bytes = self::jpeg( 800, 600, array( 120, 30, 30 ) );
		$a     = self::upload_a( 'bild.jpg', 'image/jpeg', $bytes );
		$b     = self::upload_b( 'bild.jpg', 'image/jpeg', $bytes );
		$old   = self::uploads_of( 'a' );
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'edit_image', 'post' => $b, 'suffix' => '1700000000', 'image' => array( 'w' => 600, 'h' => 800, 'rgb' => array( 30, 30, 120 ) ) ) ) );
		$neu     = self::upload_b( 'neu.jpg', 'image/jpeg', self::jpeg( 100, 100, array( 1, 1, 1 ) ) );
		$planned = self::planned( array( 'attachment:2026/10/bild.jpg', 'attachment:2026/10/neu.jpg' ) );
		self::assertSame( 'take_over', self::entry( $planned['report']['all'], 'attachment:2026/10/bild.jpg' )['action'] );
		$no_upload = static function ( $allcaps ) {
			$allcaps['upload_files'] = false;
			return $allcaps;
		};
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
		// The way back, with JPEG no type this account may upload.
		add_filter(
			'upload_mimes',
			static function ( $mimes ) {
				unset( $mimes['jpg|jpeg|jpe'] );
				return $mimes;
			}
		);
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
		self::assertSame( 'left', $parts['meta:_wp_attached_file']['state'], json_encode( $parts ) );
		self::assertStringContainsString( 'may not upload', $parts['meta:_wp_attached_file']['note'] );
		self::assertSame( 'restored', $parts['meta:_wp_attachment_metadata']['state'], json_encode( $parts ) );
		self::assertSame( array( '2026/10/bild-e1700000000.jpg' ), self::meta_a( $a, '_wp_attached_file' ), 'Left with its value before named.' );
		self::assertSame( $now, self::uploads_of( 'a' ), 'No file is deleted on the way back, not even one the run laid.' );
		self::assertSame( array(), $this->deletions );
	}
}
