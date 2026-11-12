<?php
/**
 * The trees of the protected store, their place and retention, and the
 * mark of a swap with its instruction (Z8b branch 3; plan E4, the part of
 * E6 of branch 3, 3.1 Z7 and Z8; F23 for the part of branch 3, F47 and P5
 * for the part of branch 3), on real paths with site A:
 *
 * - Z7, the normal case: a tree goes into the stage and comes back byte for
 *   byte (tree(): its manifest), with the modes of its files and folders; a
 *   file is a tree too; the old version gets a place it is renamed into and
 *   comes back by its hash; the clean-up takes the tree with its group once
 *   no run needs it;
 * - Z7, never over another and never changed: a second tree of the name is
 *   refused, write_file() writes into no tree, a tree changed by hand is not
 *   used;
 * - Z7, F23: another device of the store and the folder of the unit
 *   (code:store_device, nachgebildet), a file that configures the web
 *   server or PHP in a store in the uploads, a store in the uploads whose
 *   PHP files below stage and backups were served or not asked
 *   (code:store_web), each before anything is written, the way named;
 * - Z7, faults of the copy: false, fewer bytes, a Throwable, a mode that
 *   cannot be set, the source changed while it is copied, a link in it, an
 *   end of the process in the middle of the copy;
 * - Z8 (F47, P5 for branch 3): a backup tree stays until the retention of
 *   its run lets it go: in Free the last three qualified closes, with
 *   ab_mcp_staging_backup_days 30 also a close older than 30 days while it
 *   is one of the last three; the period stored at its close holds after
 *   the filter is gone (Pro → Free);
 * - E6: the mark and its instruction, written and read back, their fields
 *   checked, closed; the commands of each point of the instruction put the
 *   old state back in a shell (AB_MCP_IT_Handweg), a check that fails stops
 *   them; the text names the plugin active at the beginning and the race;
 * - HTTP of the self-check (the PHP files below stage and backups): an
 *   answer 200, one that holds the content, a request that throws or fails
 *   (a time limit), each leaves the proof without php; an end of the process
 *   in the middle leaves its test files, which the next check does not need.
 *
 * Kinds of fault of plan 8.3 that do not apply here, with the reason:
 * - a concurrent writer of the tree in the store: write_file() writes into
 *   no tree but the one put_tree() copies in this request, under the lock of
 *   the store; a writer outside AlphaBridge is a limit (GRENZEN-CODE.md);
 * - «halb gelesen» of the reading of a tree is AB_MCP_Sync_File_Manifest's
 *   (SyncFileManifestTest): a tree read in part has no hash, so tree() and
 *   put_tree() refuse it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Handweg;
use AB_MCP_IT_Store_Calls;
use AB_MCP_IT_Process;
use AB_MCP_IT_Sites;
use AB_MCP_Payload_File;
use AB_MCP_Protected_Store;
use AB_MCP_Protected_Store_Calls;
use AB_MCP_Store_Budget;
use AB_MCP_Sync_File_Manifest;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AB_MCP_Test_Sync_Fetcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/support/class-it-store-calls.php';

final class StoreTreesTest extends TestCase {

	private const UNIT = 'ab-it-baum';

	private const SEAM = 'naht_protected_store';

	/** @var string|null */
	private $docroot_before;

	/** @var array<int,array{0:string,1:callable}> */
	private $filters = array();

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		self::wipe();
		self::apache( false );
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}
		$this->filters = array();
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		self::apache( false );
		self::remove_tree( self::plugins() . '/' . self::UNIT );
		self::remove_tree( self::plugins() . '/' . self::UNIT . '.php' );
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

	private static function src(): string {
		return self::root() . '/run/ab-store-src';
	}

	private static function apache( bool $on ): void {
		$marker = self::root() . '/run/apache-' . basename( AB_MCP_IT_Sites::docroot( 'a' ) );
		if ( $on ) {
			touch( $marker );
		} elseif ( is_file( $marker ) ) {
			unlink( $marker );
		}
	}

	private static function wipe(): void {
		$paths   = array( self::root() . '/ab-mcp-store', self::src() );
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		foreach ( array_merge( (array) glob( $uploads . '/ab-mcp-store-*' ), (array) glob( $uploads . '/.ab-tmp-*' ), (array) glob( $uploads . '/ab-mcp-probe-*' ) ) as $path ) {
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

	/** Every file and folder below a path with its bytes (SHA-256) and mode. */
	private static function snapshot( string $path ): array {
		if ( is_file( $path ) ) {
			return array( '' => hash_file( 'sha256', $path ) . ' ' . decoct( fileperms( $path ) & 0777 ) );
		}
		$out = array();
		if ( ! is_dir( $path ) ) {
			return $out;
		}
		$out[''] = 'dir ' . decoct( fileperms( $path ) & 0777 );
		$it      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$out[ substr( (string) $file, strlen( $path ) + 1 ) ] = ( $file->isDir() ? 'dir' : hash_file( 'sha256', (string) $file ) ) . ' ' . decoct( fileperms( (string) $file ) & 0777 );
		}
		ksort( $out );
		return $out;
	}

	private static function names( string $dir ): array {
		$out = is_dir( $dir ) ? array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) : array();
		sort( $out );
		return $out;
	}

	/** A folder of a plugin with a subfolder, modes of their own and a file daten.tmp. */
	private static function source( string $path, string $version, array $more = array() ): string {
		self::remove_tree( $path );
		mkdir( $path . '/inc', 0755, true );
		file_put_contents( $path . '/' . self::UNIT . '.php', "<?php\n/* Plugin Name: AB IT Baum\n * Version: $version\n */\n" );
		file_put_contents( $path . '/inc/teil.php', "<?php // $version\n" );
		file_put_contents( $path . '/daten.tmp', "Nutzdatei $version\n" );
		file_put_contents( $path . '/leer.txt', '' );
		foreach ( $more as $rel => $bytes ) {
			@mkdir( dirname( $path . '/' . $rel ), 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			file_put_contents( $path . '/' . $rel, $bytes );
		}
		chmod( $path . '/inc/teil.php', 0640 );
		chmod( $path . '/inc', 0750 );
		return $path;
	}

	/** The hash of the manifest of a folder or file now. */
	private static function hash_of( string $path ): ?string {
		$read = AB_MCP_Sync_File_Manifest::unit( dirname( $path ), basename( $path ), 'live', microtime( true ) + 45, true, 'tree' );
		return $read['complete'] ? $read['hash'] : null;
	}

	/** A group of an area of site A's store for a run, and the folder of it. */
	private static function group( string $area, int $run ): array {
		$gid = AB_MCP_Protected_Store::open( $area, array( 'run_id' => $run ) );
		self::assertIsString( $gid );
		return array( $gid, (string) AB_MCP_Protected_Store::root() . '/' . $area . '/' . AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, AB_MCP_Protected_Store::install_id(), '' ) );
	}

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

	private function filter( string $hook, callable $fn ): void {
		add_filter( $hook, $fn );
		$this->filters[] = array( $hook, $fn );
	}

	/** Site A's store in its uploads, proved by the router as Apache (the PHP files below stage and backups refused too). */
	private static function uploads_store( array $faults = array() ): array {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( true );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		if ( array() !== $faults ) {
			AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, new AB_MCP_IT_Store_Calls( $fetcher ), $faults );
		}
		AB_MCP_Protected_Store::forget();
		$proof = AB_MCP_Protected_Store::proof( $fetcher, true );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( 'uploads', $proof['where'], (string) $proof['message'] );
		return $proof;
	}

	/* ------------------------------------------- Z7: the normal case */

	public function testATreeGoesIntoTheStageAndComesBackByteForByte(): void {
		self::run_row( 6, 'open' );
		$src = self::source( self::src(), '2.0.0' );
		list( $gid, $dir ) = self::group( 'stage', 6 );
		$put = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
		self::assertIsArray( $put, is_wp_error( $put ) ? $put->get_error_message() : '' );
		self::assertTrue( $put['copied'] );
		self::assertSame( 'outside', $put['where'] );
		self::assertSame( 'folder', $put['form'] );
		self::assertSame( self::hash_of( $src ), $put['hash'] );
		self::assertSame( $dir . '/' . self::UNIT, $put['path'] );
		self::assertSame( self::snapshot( $src ), self::snapshot( $put['path'] ), 'Byte for byte, with the modes of its files and folders.' );
		$back = AB_MCP_Protected_Store::tree( 'stage', $gid, self::UNIT, $put['hash'] );
		self::assertIsArray( $back );
		self::assertSame( $put['path'], $back['path'] );
		self::assertSame( array(), self::names( (string) AB_MCP_Protected_Store::root() . '/tmp' ), 'No temp file stays.' );
		// A file is a tree too (a plugin of one file).
		file_put_contents( self::src() . '-einzeln.php', "<?php\n/* Plugin Name: Einzeln */\n" );
		self::assertSame( 'ab_mcp_store_tree_name', AB_MCP_Protected_Store::put_tree( 'stage', $gid, 'anders.php', self::plugins() . '/einzeln.php', self::src() . '-einzeln.php' )->get_error_code(), 'A file is named as the file of its unit.' );
		$one = AB_MCP_Protected_Store::put_tree( 'stage', $gid, 'einzeln.php', self::plugins() . '/einzeln.php', self::src() . '-einzeln.php' );
		self::assertIsArray( $one, is_wp_error( $one ) ? $one->get_error_message() : '' );
		self::assertSame( 'file', $one['form'] );
		self::assertSame( (string) file_get_contents( self::src() . '-einzeln.php' ), (string) file_get_contents( $one['path'] ) );
		self::assertIsArray( AB_MCP_Protected_Store::tree( 'stage', $gid, 'einzeln.php', $one['hash'] ) );
		unlink( self::src() . '-einzeln.php' );
		// While its run is not closed the clean-up keeps it, then it goes with its group.
		AB_MCP_Protected_Store::cleanup( time() + 2 * 86400 );
		self::assertSame( self::snapshot( $src ), self::snapshot( $put['path'] ) );
		global $wpdb;
		$wpdb->update( AB_MCP_Sync_Schema::table( 'runs' ), array( 'state' => 'closed_done' ), array( 'run_id' => 6 ) );
		AB_MCP_Sync_Schema::forget();
		AB_MCP_Protected_Store::cleanup( time() + 2 * 86400 );
		self::assertFileDoesNotExist( $dir, 'Nobody names it any more: it goes with its group.' );
	}

	public function testTheOldVersionGetsAPlaceItIsRenamedIntoAndComesBackByItsHash(): void {
		$target = self::source( self::plugins() . '/' . self::UNIT, '1.0.0' );
		$before = self::snapshot( $target );
		list( $gid, $dir ) = self::group( 'backups', 6 );
		$place = AB_MCP_Protected_Store::put_tree( 'backups', $gid, self::UNIT, $target );
		self::assertIsArray( $place );
		self::assertFalse( $place['copied'] );
		self::assertSame( self::hash_of( $target ), $place['hash'], 'The hash of what will go in, read from the target.' );
		self::assertFileDoesNotExist( $place['path'], 'Only the place: nothing is made there.' );
		self::assertSame( $before, self::snapshot( $target ), 'The target is not touched.' );
		self::assertTrue( rename( $target, $place['path'] ), 'The swap of branch 5 renames it there.' );
		$back = AB_MCP_Protected_Store::tree( 'backups', $gid, self::UNIT, $place['hash'] );
		self::assertIsArray( $back );
		self::assertSame( $before, self::snapshot( $back['path'] ) );
	}

	public function testATreeIsNeverPutOverAnotherNorChangedInTheStore(): void {
		$src = self::source( self::src(), '2.0.0' );
		list( $gid, $dir ) = self::group( 'stage', 6 );
		$put = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
		self::assertIsArray( $put );
		$kept = self::snapshot( $put['path'] );
		self::assertSame( 'ab_mcp_store_tree_exists', AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src )->get_error_code() );
		self::assertSame( 'ab_mcp_store_tree_exists', AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT )->get_error_code(), 'No place over it either.' );
		self::assertSame( 'ab_mcp_store_exists', AB_MCP_Protected_Store::put( 'stage', $gid, self::UNIT, 'x' )->get_error_code(), 'No file of a group over it.' );
		$root = (string) AB_MCP_Protected_Store::root();
		foreach ( array( $put['path'] . '/neu.php', $put['path'] . '/inc/teil.php' ) as $path ) {
			self::assertSame( 'ab_mcp_store_tree', AB_MCP_Protected_Store::write_file( $root, $path, 'x' )->get_error_code(), $path );
			self::assertSame( 'ab_mcp_store_tree', AB_MCP_Protected_Store::write_file( $root, $path, 'x', array( 'exclusive' => true ) )->get_error_code(), $path . ': not even a new file, outside of put_tree().' );
		}
		self::assertSame( 'ab_mcp_store_tree', AB_MCP_Protected_Store::write_file( $root, $put['path'], 'x' )->get_error_code(), 'Not over the tree.' );
		self::assertSame( 'ab_mcp_store_exists', AB_MCP_Protected_Store::write_file( $root, $put['path'], 'x', array( 'exclusive' => true ) )->get_error_code(), 'Written once.' );
		self::assertSame( $kept, self::snapshot( $put['path'] ), 'Nothing of it was changed.' );
		self::assertSame( array(), self::names( $root . '/tmp' ) );
		// Changed by hand: not used.
		file_put_contents( $put['path'] . '/inc/teil.php', '<?php // anders' );
		self::assertSame( 'ab_mcp_store_tree_changed', AB_MCP_Protected_Store::tree( 'stage', $gid, self::UNIT, $put['hash'] )->get_error_code() );
		self::assertSame( 'ab_mcp_store_tree_missing', AB_MCP_Protected_Store::tree( 'stage', $gid, 'fehlt', $put['hash'] )->get_error_code() );
		self::assertSame( 'ab_mcp_store_area', AB_MCP_Protected_Store::put_tree( 'out', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src )->get_error_code() );
		self::assertSame( 'ab_mcp_store_tree_path', AB_MCP_Protected_Store::put_tree( 'stage', $gid, 'b', 'wp-content/plugins/b', $src )->get_error_code() );
		self::assertSame( 'ab_mcp_store_tree_path', AB_MCP_Protected_Store::put_tree( 'stage', $gid, 'b', self::plugins() . '/../b', $src )->get_error_code() );
	}

	/* ---------------------------------------- Z7, F23: the place of a tree */

	public function testAnotherDeviceOfTheStoreAndTheFolderOfTheUnitPutsNothingAndNamesTheWay(): void {
		$src = self::source( self::src(), '2.0.0' );
		list( $gid, $dir ) = self::group( 'stage', 6 );
		$other = array(
			'dev'  => -1,
			'ino'  => 1,
			'mode' => 040755,
		);
		foreach ( array( $other, false ) as $answer ) {
			AB_MCP_Test_Seam::install(
				AB_MCP_Protected_Store::class,
				self::SEAM,
				AB_MCP_Protected_Store_Calls::class,
				array(
					array(
						'method' => 'stat',
						'nth'    => 2,
						'kind'   => 'return',
						'value'  => $answer,
					),
				)
			);
			$res = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( 'code:store_device', $res->get_error_code() );
			self::assertSame( 'wp-content/plugins', $res->get_error_data()['folder'] );
			self::assertStringContainsString( 'wp-content/plugins', $res->get_error_message() );
			self::assertStringContainsString( 'update the plugin or theme by hand in wp-admin', $res->get_error_message() );
			self::assertSame( array(), self::names( $dir ), 'Nothing went into the group.' );
		}
		// A stat() that throws: nothing either, the caller sees it.
		AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, AB_MCP_Protected_Store_Calls::class, array( array( 'method' => 'stat', 'kind' => 'throw' ) ) );
		try {
			AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
			self::fail( 'A stat() that throws is passed on.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'Fault injected', $e->getMessage() );
		}
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array(), self::names( $dir ) );
		self::assertIsArray( AB_MCP_Protected_Store::groups( 'stage' ), 'The lock of the store was given back.' );
	}

	public function testInTheUploadsATreeNeedsThePhpProofAndNoFileThatConfiguresTheServer(): void {
		$proof = self::uploads_store();
		self::assertTrue( $proof['php'] );
		list( $gid, $dir ) = self::group( 'stage', 6 );
		$plain = self::source( self::src(), '2.0.0' );
		$put   = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $plain );
		self::assertIsArray( $put, is_wp_error( $put ) ? $put->get_error_message() : '' );
		self::assertSame( 'uploads', $put['where'] );
		foreach ( array( 'inc/.htaccess', 'web.config', 'tief/x/.User.ini', 'php.ini.', 'WEB.CONFIG' ) as $i => $config ) {
			$src = self::source( self::src() . '-' . $i, '2.0.0', array( $config => "x\n" ) );
			$res = AB_MCP_Protected_Store::put_tree( 'stage', $gid, 'mit-' . $i, self::plugins() . '/' . self::UNIT, $src );
			self::assertSame( 'code:store_web', $res->get_error_code(), $config );
			self::assertSame( 'server_config', $res->get_error_data()['why'] );
			self::assertSame( array( $config ), $res->get_error_data()['files'] );
			self::assertStringContainsString( 'beside the WordPress folder', $res->get_error_message() );
			self::assertFileDoesNotExist( $dir . '/mit-' . $i );
			self::remove_tree( $src );
		}
		// Old versions too: the place of a backup is refused the same way.
		$target = self::source( self::plugins() . '/' . self::UNIT, '1.0.0', array( '.htaccess' => "Require all granted\n" ) );
		list( $bgid ) = self::group( 'backups', 6 );
		self::assertSame( 'code:store_web', AB_MCP_Protected_Store::put_tree( 'backups', $bgid, self::UNIT, $target )->get_error_code() );
		self::remove_tree( $target );
		self::remove_tree( self::src() );
		// Beside WordPress such a tree is fine.
		self::wipe();
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		list( $gid ) = self::group( 'stage', 6 );
		$src = self::source( self::src(), '2.0.0', array( 'inc/.htaccess' => "Require all denied\n" ) );
		$put = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
		self::assertIsArray( $put );
		self::assertSame( 'outside', $put['where'] );
	}

	public function testInTheUploadsWithoutThePhpProofNoTreeGoesIn(): void {
		$served = array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(),
			'body'     => 'ab-mcp-ran-',
			'cookies'  => array(),
		);
		$timeout = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$cases   = array(
			'a PHP file served (200)'     => array( 'kind' => 'return', 'value' => $served ),
			'a request that throws'       => array( 'kind' => 'throw' ),
			'a request that runs out'     => array( 'kind' => 'return', 'value' => $timeout ),
		);
		foreach ( $cases as $what => $fault ) {
			self::wipe();
			$proof = self::uploads_store( array( array( 'method' => 'ask', 'nth' => 5 ) + $fault ) );
			self::assertTrue( $proof['available'], $what . ': the folder is proved for packages and rows.' );
			self::assertFalse( $proof['php'], $what );
			list( $gid, $dir ) = self::group( 'stage', 6 );
			$src = self::source( self::src(), '2.0.0' );
			$res = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
			self::assertSame( 'code:store_web', $res->get_error_code(), $what );
			self::assertSame( 'php_proof', $res->get_error_data()['why'], $what );
			self::assertSame( array(), self::names( $dir ), $what );
			$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
			self::assertSame( array(), preg_grep( '/^ab-mcp-probe-/', self::names( $uploads ) ), $what . ': nothing of the check stays.' );
			foreach ( array( 'stage', 'backups' ) as $area ) {
				self::assertSame( array(), preg_grep( '/^ab-probe-/', self::names( (string) AB_MCP_Protected_Store::root() . '/' . $area ) ), $what );
			}
		}
	}

	public function testAPhpFileAnsweredWithItsContentOrRunIsNoProof(): void {
		$ok     = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'tok',
		);
		$denied = array(
			'response' => array( 'code' => 403 ),
			'body'     => '',
		);
		$judge  = static fn( array $php ): array => AB_MCP_Protected_Store::judge( $ok, array( $denied, $denied, $denied ), 'tok', $php );
		self::assertTrue( $judge( array( $denied, array( 'response' => array( 'code' => 404 ), 'body' => 'Not Found' ) ) )['detail']['php'] );
		self::assertFalse( $judge( array( $denied, array( 'response' => array( 'code' => 404 ), 'body' => "<?php echo 'ab-mcp-' . 'ran-' . 'tok';" ) ) )['detail']['php'], 'A 404 whose body is the file: served.' );
		self::assertFalse( $judge( array( array( 'response' => array( 'code' => 403 ), 'body' => 'ab-mcp-ran-tok' ), $denied ) )['detail']['php'], 'A 403 whose body is what it prints: it ran.' );
		self::assertFalse( $judge( array( $denied ) )['detail']['php'], 'One answer missing.' );
		self::assertFalse( $judge( array( $denied, new \WP_Error( 'x' ) ) )['detail']['php'] );
		self::assertArrayNotHasKey( 'php', AB_MCP_Protected_Store::judge( $ok, array( $denied, $denied, $denied ), 'tok' )['detail'], 'Not asked: no word on it.' );
		self::assertSame( 'served', AB_MCP_Protected_Store::judge( $ok, array( $denied, $ok, $denied ), 'tok', array( $denied, $denied ) )['reason'], 'Where the test files are served, the PHP files change nothing.' );
	}

	public function testAStoreInTheUploadsProvedBeforeThePhpFilesWereAskedIsCheckedAnewForATree(): void {
		self::uploads_store();
		// The record as an older version made it: no php in its detail.
		$rec = get_option( AB_MCP_Protected_Store::OPTION_PROOF );
		unset( $rec['seen']['uploads']['ok']['detail']['php'], $rec['seen']['uploads']['ok']['detail']['php_probes'] );
		update_option( AB_MCP_Protected_Store::OPTION_PROOF, $rec, false );
		AB_MCP_Protected_Store::forget();
		self::assertNull( AB_MCP_Protected_Store::proof()['php'] );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, new AB_MCP_IT_Store_Calls( $fetcher ), array() );
		list( $gid ) = self::group( 'stage', 6 );
		$src = self::source( self::src(), '2.0.0' );
		$put = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
		AB_MCP_Test_Seam::restore_all();
		self::assertIsArray( $put, is_wp_error( $put ) ? $put->get_error_message() : '' );
		self::assertCount( 6, $fetcher->calls, 'The check was made anew, with the PHP files.' );
		self::assertTrue( AB_MCP_Protected_Store::proof()['php'] );
	}

	/* -------------------------------------------- Z7: faults of the copy */

	public function testACopyThatFailsLeavesNothingOfTheTree(): void {
		list( $gid, $dir ) = self::group( 'stage', 6 );
		$src   = self::source( self::src(), '2.0.0' );
		$root  = (string) AB_MCP_Protected_Store::root();
		$cases = array(
			'copy false'    => array( array( 'method' => 'copy_into', 'nth' => 2, 'kind' => 'return', 'value' => false ), 'ab_mcp_store_write' ),
			'fewer bytes'   => array( array( 'method' => 'copy_into', 'nth' => 2, 'kind' => 'return', 'value' => 3 ), 'ab_mcp_store_write' ),
			'copy throws'   => array( array( 'method' => 'copy_into', 'nth' => 2, 'kind' => 'throw' ), 'ab_mcp_store_write' ),
			'mkdir throws'  => array( array( 'method' => 'mkdir', 'nth' => 2, 'kind' => 'throw' ), 'ab_mcp_store_write' ),
			'mode refused'  => array( array( 'method' => 'chmod', 'nth' => 3, 'kind' => 'return', 'value' => false ), 'ab_mcp_store_write' ),
			'source writer' => array(
				array(
					'method' => 'copy_into',
					'nth'    => 2,
					'kind'   => 'before',
					'before' => array(
						array(
							'op'    => 'put_file',
							'path'  => $src . '/inc/teil.php',
							'bytes' => base64_encode( '<?php // changed while copied' ),
						),
					),
				),
				'ab_mcp_store_tree_changed',
			),
		);
		foreach ( $cases as $what => $case ) {
			self::source( $src, '2.0.0' );
			AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, AB_MCP_Protected_Store_Calls::class, array( $case[0] ) );
			$res = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( $case[1], $res->get_error_code(), $what );
			self::assertSame( array(), self::names( $dir ), $what . ': nothing of the tree stays.' );
			self::assertSame( array(), self::names( $root . '/tmp' ), $what . ': no temp file.' );
		}
		// A link in the source: not read whole, nothing copied.
		self::source( $src, '2.0.0' );
		symlink( '/etc/hosts', $src . '/inc/link' );
		$res = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
		self::assertSame( 'ab_mcp_store_tree_unread', $res->get_error_code() );
		self::assertSame( 'link', $res->get_error_data()['cause'] );
		self::assertSame( array(), self::names( $dir ) );
	}

	public function testAnEndOfTheProcessInTheMiddleOfACopyLeavesAHalfTreeThatIsNeverUsed(): void {
		list( $gid, $dir ) = self::group( 'stage', 6 );
		$src  = self::source( self::src(), '2.0.0' );
		$hash = self::hash_of( $src );
		AB_MCP_IT_Process::abort(
			'a',
			array(
				'seams' => array(
					array(
						'owner'    => AB_MCP_Protected_Store::class,
						'property' => self::SEAM,
						'real'     => AB_MCP_Protected_Store_Calls::class,
						'faults'   => array(
							array(
								'method' => 'copy_into',
								'nth'    => 3,
								'kind'   => 'abort',
								'point'  => 'mitten-im-kopieren',
								'mode'   => 'no_shutdown',
							),
						),
					),
				),
				'calls' => array(
					array(
						'call' => 'AB_MCP_Protected_Store::put_tree',
						'args' => array( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src ),
					),
				),
			)
		);
		self::assertDirectoryExists( $dir . '/' . self::UNIT, 'The half tree stays in its group.' );
		self::assertSame( 'ab_mcp_store_tree_changed', AB_MCP_Protected_Store::tree( 'stage', $gid, self::UNIT, (string) $hash )->get_error_code(), 'It is never used: its manifest is not the one of the source.' );
		self::assertSame( 'ab_mcp_store_tree_exists', AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src )->get_error_code(), 'Nor written over: a new tree takes another name or group.' );
		$root = (string) AB_MCP_Protected_Store::root();
		self::assertCount( 1, self::names( $root . '/tmp' ), 'The temp file of the file being copied lies in tmp/.' );
		foreach ( self::names( $root . '/tmp' ) as $file ) {
			touch( $root . '/tmp/' . $file, time() - AB_MCP_Protected_Store::TMP_AGE - 10 );
		}
		AB_MCP_Protected_Store::cleanup( time() + 2 * 86400 );
		self::assertFileDoesNotExist( $dir, 'It goes with its group, as nobody names it.' );
		self::assertSame( array(), self::names( $root . '/tmp' ) );
	}

	/* ------------------------------------------------ Z8: the retention */

	public function testABackupTreeStaysUntilItsRetentionLetsItGo(): void {
		$day   = 86400;
		$now   = time();
		$src   = self::source( self::src(), '1.0.0' );
		$trees = array();
		// Free: a qualified close keeps its backups without an age while it
		// is one of the last three (keep_until is the close itself).
		foreach ( array( 1 => 40, 2 => 30, 3 => 20, 4 => 10 ) as $run => $ago ) {
			self::run_row( $run, 'closed_done', $now - $ago * $day, $now - $ago * $day );
			list( $gid, $dir ) = self::group( 'backups', $run );
			$put               = AB_MCP_Protected_Store::put_tree( 'backups', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
			self::assertIsArray( $put );
			$trees[ $run ] = array( $gid, $dir, $put['hash'] );
		}
		AB_MCP_Protected_Store::cleanup( $now );
		self::assertFileDoesNotExist( $trees[1][1], 'The fourth newest close: displaced, its tree went.' );
		foreach ( array( 2, 3, 4 ) as $run ) {
			self::assertIsArray( AB_MCP_Protected_Store::tree( 'backups', $trees[ $run ][0], self::UNIT, $trees[ $run ][2] ), "Run $run is one of the last three: its tree stays byte for byte, whatever its age." );
		}
		// A further qualified close displaces the oldest of them.
		self::run_row( 5, 'closed_done', $now - $day, $now - $day );
		AB_MCP_Protected_Store::cleanup( $now );
		self::assertFileDoesNotExist( $trees[2][1] );
		self::assertIsArray( AB_MCP_Protected_Store::tree( 'backups', $trees[3][0], self::UNIT, $trees[3][2] ) );
	}

	public function testWithThirtyDaysABackupTreeStaysAlsoOlderWhileItIsOneOfTheLastThreeAndItsPeriodStaysWithIt(): void {
		$day = 86400;
		$now = time();
		$this->filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn(): int => 30 );
		$src = self::source( self::src(), '1.0.0' );
		// A close 31 days ago (its 30 days over) with two newer ones: it is
		// one of the last three, its tree stays.
		self::run_row( 1, 'closed_done', $now - 31 * $day + 30 * $day, $now - 31 * $day );
		self::run_row( 2, 'closed_done', $now - 10 * $day + 30 * $day, $now - 10 * $day );
		self::run_row( 3, 'closed_done', $now - 5 * $day + 30 * $day, $now - 5 * $day );
		list( $gid, $dir ) = self::group( 'backups', 1 );
		$put               = AB_MCP_Protected_Store::put_tree( 'backups', $gid, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
		AB_MCP_Protected_Store::cleanup( $now );
		self::assertIsArray( AB_MCP_Protected_Store::tree( 'backups', $gid, self::UNIT, $put['hash'] ) );
		// A third newer close: older than 30 days and not one of the last three.
		self::run_row( 4, 'closed_done', $now - $day + 30 * $day, $now - $day );
		AB_MCP_Protected_Store::cleanup( $now );
		self::assertFileDoesNotExist( $dir );
		// Pro → Free: a close not among the last three keeps the period it
		// got at its close.
		list( $gid2, $dir2 ) = self::group( 'backups', 2 );
		$put2                = AB_MCP_Protected_Store::put_tree( 'backups', $gid2, self::UNIT, self::plugins() . '/' . self::UNIT, $src );
		self::run_row( 9, 'closed_done', $now + 30 * $day, $now );
		remove_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, $this->filters[0][1] );
		AB_MCP_Protected_Store::cleanup( $now );
		self::assertIsArray( AB_MCP_Protected_Store::tree( 'backups', $gid2, self::UNIT, $put2['hash'] ), 'Run 2 is no longer one of the last three, its 30 days hold in Free.' );
		AB_MCP_Protected_Store::cleanup( $now + 21 * $day );
		self::assertFileDoesNotExist( $dir2, 'And end.' );
	}

	/* ----------------------------------------- E6: mark and instruction */

	/** The swap of branch 5 at its points, made by hand: a backup place, the new version in stage, and the mark. */
	private function swap_with_mark( array $active = array() ): array {
		$target = self::source( self::plugins() . '/' . self::UNIT, '1.0.0' );
		$old    = stat( $target );
		$before = self::snapshot( $target );
		list( $bgid ) = self::group( 'backups', 6 );
		list( $sgid ) = self::group( 'stage', 6 );
		$new   = AB_MCP_Protected_Store::put_tree( 'stage', $sgid, self::UNIT, $target, self::source( self::src(), '2.0.0' ) );
		$place = AB_MCP_Protected_Store::put_tree( 'backups', $bgid, self::UNIT, $target );
		self::assertIsArray( $new );
		self::assertIsArray( $place );
		$sstat = stat( $new['path'] );
		$mark  = AB_MCP_Protected_Store::put_mark(
			array(
				'run_id' => 6,
				'seq'    => 2,
				'op'     => 'swap',
				'unit'   => array(
					'kind' => 'plugin',
					'ref'  => self::UNIT,
				),
				'target' => $target,
				'trees'  => array(
					array(
						'role' => 'backup',
						'path' => $place['path'],
						'form' => 'folder',
						'dev'  => (int) $old['dev'],
						'ino'  => (int) $old['ino'],
						'hash' => $place['hash'],
					),
					array(
						'role' => 'stage',
						'path' => $new['path'],
						'form' => 'folder',
						'dev'  => (int) $sstat['dev'],
						'ino'  => (int) $sstat['ino'],
						'hash' => $new['hash'],
					),
				),
				'active' => $active,
			)
		);
		self::assertIsArray( $mark, is_wp_error( $mark ) ? $mark->get_error_message() : '' );
		return array(
			'target' => $target,
			'backup' => $place['path'],
			'stage'  => $new['path'],
			'old'    => $before,
			'new'    => self::snapshot( $new['path'] ),
			'mark'   => $mark,
		);
	}

	public function testTheInstructionPutsTheOldStateBackByHandAtEachPoint(): void {
		$points = array(
			'vor-den-umbenennungen'     => array(),
			'zwischen-den-umbenennungen' => array( 'out' ),
			'nach-den-umbenennungen'    => array( 'out', 'in' ),
			'waehrend-des-ruecktauschs' => array( 'out' ),
			'nach-der-aktivierung'      => array( 'out', 'in' ),
		);
		$points['waehrend-des-ruecktauschs (new at the target)'] = array( 'out', 'in' );
		foreach ( $points as $label => $moves ) {
			$point = explode( ' ', $label )[0];
			$s     = $this->swap_with_mark();
			foreach ( $moves as $move ) {
				self::assertTrue( 'out' === $move ? rename( $s['target'], $s['backup'] ) : rename( $s['stage'], $s['target'] ), $label );
			}
			$run = AB_MCP_IT_Handweg::run_point( $s['mark']['guide'], $point, self::root() );
			self::assertSame( 0, $run['status'], $label . ': ' . $run['stderr'] . $run['output'] );
			self::assertSame( $s['old'], self::snapshot( $s['target'] ), $label . ': the old version at the target.' );
			if ( in_array( 'in', $moves, true ) ) {
				self::assertSame( $s['new'], self::snapshot( $s['stage'] ), $label . ': the new version back in its place.' );
			}
			self::remove_tree( $s['target'] );
			self::wipe();
			AB_MCP_Protected_Store::forget();
		}
		// A check that fails stops the commands: the target is there, so
		// «between the renames» moves nothing.
		$s   = $this->swap_with_mark();
		$run = AB_MCP_IT_Handweg::run_point( $s['mark']['guide'], 'zwischen-den-umbenennungen', self::root() );
		self::assertNotSame( 0, $run['status'] );
		self::assertSame( $s['old'], self::snapshot( $s['target'] ) );
		self::assertFileDoesNotExist( $s['backup'] );
		self::assertSame( $s['new'], self::snapshot( $s['stage'] ) );
	}

	public function testTheInstructionNamesWhatMovedAndForAnActivePluginTheWayAndTheRace(): void {
		$s    = $this->swap_with_mark( array( self::UNIT . '/' . self::UNIT . '.php' ) );
		$text = (string) file_get_contents( $s['mark']['guide'] );
		foreach ( array( 'Unit: plugin ' . self::UNIT, 'Target (the folder of the unit in the site): ' . $s['target'], 'Backup (the old version): ' . $s['backup'], 'Stage (the new version): ' . $s['stage'], 'Run 6, step 2', 'ls -di' ) as $needle ) {
			self::assertStringContainsString( $needle, $text );
		}
		foreach ( array( 'vor-den-umbenennungen', 'zwischen-den-umbenennungen', 'nach-den-umbenennungen', 'waehrend-des-ruecktauschs', 'nach-der-aktivierung' ) as $point ) {
			self::assertNotEmpty( AB_MCP_IT_Handweg::commands( $text, $point ), $point );
		}
		self::assertStringContainsString( 'The plugin ' . self::UNIT . '/' . self::UNIT . '.php was active when the swap began.', $text );
		self::assertStringContainsString( 'way (a) with its approval in accept', $text );
		self::assertStringContainsString( 'Plugins → Activate in wp-admin by hand', $text );
		self::assertStringContainsString( 'A request to the page Plugins at the same time can still deactivate the plugin after the swap; the next report shows it then.', $text );
		$mark = json_decode( (string) file_get_contents( $s['mark']['mark'] ), true );
		self::assertSame( array( self::UNIT . '/' . self::UNIT . '.php' ), $mark['active'], 'The mark holds the main files active at the beginning.' );
		self::assertSame( 'open', $mark['state'] );
	}

	public function testAMarkIsWrittenWholeReadBackAndClosedAndItsFieldsAreChecked(): void {
		$s    = $this->swap_with_mark();
		$root = (string) AB_MCP_Protected_Store::root();
		$list = AB_MCP_Protected_Store::marks();
		self::assertSame( array( $s['mark']['mid'] ), array_column( $list['marks'], 'mid' ) );
		self::assertSame( array(), $list['unreadable'] );
		self::assertSame( array(), self::names( $root . '/tmp' ) );
		self::assertTrue( AB_MCP_Protected_Store::close_mark( $s['mark']['mid'] ) );
		self::assertSame( 'closed', AB_MCP_Protected_Store::marks()['marks'][0]['state'] );
		self::assertTrue( AB_MCP_Protected_Store::close_mark( $s['mark']['mid'] ), 'Closed once, closed.' );
		self::assertSame( 'ab_mcp_store_no_mark', AB_MCP_Protected_Store::close_mark( str_repeat( 'a', 32 ) )->get_error_code() );
		self::assertSame( 'ab_mcp_store_no_mark', AB_MCP_Protected_Store::close_mark( '../x' )->get_error_code() );
		// A mark that cannot be read is named as such.
		file_put_contents( $root . '/marks/' . $s['mark']['mid'] . '/mark.json', '{' );
		self::assertSame( array( $s['mark']['mid'] ), AB_MCP_Protected_Store::marks()['unreadable'] );
		self::assertSame( 'ab_mcp_store_mark_read', AB_MCP_Protected_Store::close_mark( $s['mark']['mid'] )->get_error_code() );

		$base = array(
			'run_id' => 1,
			'seq'    => 1,
			'op'     => 'swap',
			'unit'   => array(
				'kind' => 'plugin',
				'ref'  => self::UNIT,
			),
			'target' => $s['target'],
			'trees'  => array(
				array(
					'role' => 'backup',
					'path' => $s['backup'],
					'form' => 'folder',
					'dev'  => 1,
					'ino'  => 2,
					'hash' => null,
				),
			),
		);
		$bad = array(
			'run_id'  => array( 'run_id' => -1 ),
			'op'      => array( 'op' => 'Swap!' ),
			'unit'    => array( 'unit' => array( 'kind' => 'plugin' ) ),
			'target'  => array( 'target' => 'wp-content/plugins/x' ),
			'no tree' => array( 'trees' => array() ),
			'outside' => array( 'trees' => array( array( 'path' => self::plugins() . '/x' ) + $base['trees'][0] ) ),
			'in out'  => array( 'trees' => array( array( 'path' => $root . '/out/' . str_repeat( 'a', 40 ) . '/x' ) + $base['trees'][0] ) ),
			'twice'   => array( 'trees' => array( $base['trees'][0], $base['trees'][0] ) ),
			'role'    => array( 'trees' => array( array( 'role' => 'neu' ) + $base['trees'][0] ) ),
			'hash'    => array( 'trees' => array( array( 'hash' => 'xyz' ) + $base['trees'][0] ) ),
			'active'  => array( 'active' => array( "a\nb" ) ),
		);
		$before = self::names( $root . '/marks' );
		foreach ( $bad as $what => $change ) {
			self::assertSame( 'ab_mcp_store_mark', AB_MCP_Protected_Store::put_mark( $change + $base )->get_error_code(), $what );
		}
		self::assertSame( $before, self::names( $root . '/marks' ), 'Nothing written for a mark refused.' );
		// A write of the mark that fails, and one that does not read back.
		foreach ( array( array( 'method' => 'rename', 'kind' => 'return', 'value' => false ), array( 'method' => 'read', 'kind' => 'return', 'value' => 'other bytes' ) ) as $fault ) {
			AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, AB_MCP_Protected_Store_Calls::class, array( $fault ) );
			$res = AB_MCP_Protected_Store::put_mark( $base );
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( 'rename' === $fault['method'] ? 'ab_mcp_store_write' : 'ab_mcp_store_mark_read', $res->get_error_code(), $fault['method'] );
		}
	}

	/* ------------------------------------- rows: the bookings, HTTP: exit */

	public function testABookingThatCannotBeWrittenLeavesTheBookingsAsTheyWere(): void {
		self::run_row( 7, 'open' );
		self::run_row( 8, 'open' );
		self::assertIsArray( AB_MCP_Store_Budget::book( 7, 100 ) );
		$root = (string) AB_MCP_Protected_Store::root();
		$was  = (string) file_get_contents( $root . '/bookings.json' );
		// The test write is the first write, the bookings the second.
		foreach ( array( array( 'kind' => 'return', 'value' => false ), array( 'kind' => 'throw' ) ) as $fault ) {
			AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, AB_MCP_Protected_Store_Calls::class, array( array( 'method' => 'rename', 'nth' => 2 ) + $fault ) );
			$res = AB_MCP_Store_Budget::book( 8, 100 );
			AB_MCP_Test_Seam::restore_all();
			self::assertSame( 'ab_mcp_store_write', $res->get_error_code(), $fault['kind'] );
			self::assertSame( $was, (string) file_get_contents( $root . '/bookings.json' ), $fault['kind'] );
			self::assertSame( array(), self::names( $root . '/tmp' ), $fault['kind'] );
		}
		AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, AB_MCP_Protected_Store_Calls::class, array( array( 'method' => 'put', 'nth' => 2, 'kind' => 'half', 'arg' => 1, 'keep' => 4, 'value' => 4 ) ) );
		self::assertSame( 'ab_mcp_store_write', AB_MCP_Store_Budget::book( 8, 100 )->get_error_code() );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( $was, (string) file_get_contents( $root . '/bookings.json' ) );
		self::assertSame( array( 7 ), array_keys( AB_MCP_Store_Budget::bookings() ) );
	}

	public function testAnEndOfTheProcessInTheSelfCheckLeavesItsTestFilesWhichTheNextCheckDoesNotNeed(): void {
		self::uploads_store();
		$store = (string) AB_MCP_Protected_Store::root();
		AB_MCP_IT_Process::abort(
			'a',
			array(
				'seams' => array(
					array(
						'owner'    => AB_MCP_Protected_Store::class,
						'property' => self::SEAM,
						'real'     => AB_MCP_IT_Store_Calls::class,
						'faults'   => array(
							array(
								'method' => 'ask',
								'nth'    => 5,
								'kind'   => 'abort',
								'point'  => 'php-probe',
								'mode'   => 'no_shutdown',
							),
						),
					),
				),
				'require' => array( __DIR__ . '/support/class-it-store-calls.php' ),
				'calls'   => array( array( 'call' => 'AB_MCP_Protected_Store::proof', 'args' => array( null, true ) ) ),
			)
		);
		self::assertCount( 1, preg_grep( '/^ab-probe-[0-9a-f]{16}\z/', self::names( $store . '/stage' ) ), 'The folder of the PHP test file below stage stays (limit G-3-2).' );
		self::assertCount( 2, preg_grep( '/^ab-probe-[0-9a-f]{16}\z/', self::names( $store . '/backups' ) ), 'Below backups its folder and the test file of the check.' );
		$proof = self::uploads_store();
		self::assertTrue( $proof['php'], 'The next check passes beside them.' );
	}
}
