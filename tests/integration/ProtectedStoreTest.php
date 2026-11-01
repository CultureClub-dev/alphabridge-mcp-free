<?php
/**
 * The protected store on real paths, with two sites, over HTTP
 * (AB_MCP_Protected_Store, AB_MCP_Store_Budget; Z8 decisions 7, 8 and 20):
 *
 * - beside WordPress and outside the web root, the store is
 *   AB_IT_ROOT/ab-mcp-store/<id>/ with its manifest; the id is one row
 *   without autoload;
 * - under the document root (WordPress below it, or ab-mcp-store a link into
 *   it) nothing is made beside WordPress and the uploads are tried;
 * - there the production self-check asks through AB_MCP_Outbound, which
 *   refuses the local sites: no proof; through the test fetcher `php -S`
 *   serves the test files, which reads no .htaccess: no proof, and nothing
 *   is written; a server that refuses the folder (the router as Apache)
 *   gives the proof, which is made again after a day and when a guard file
 *   is missing; a check that fails takes the proof back; of two requests
 *   at once, a failure seen is never lost to a success stored meanwhile,
 *   and a success that began before a stored failure is not stored; a
 *   proof a request keeps is no release after another request revoked it;
 * - the place chosen stays bound while it can be used, and a request that
 *   chose a place another request has left writes nothing there;
 * - no link on the way from the folder of the store to a file is followed;
 * - without the document root only a proof of the last day counts;
 * - two installations side by side (sites A and B in one parent folder)
 *   each use, clean and uninstall only their own folder; a copy that kept
 *   the id of the other gets an id of its own and leaves the other's folder;
 * - a file is written once, through a rename, and read only with its
 *   SHA-256; a package takes no more than it declared;
 * - the budgets hold under the lock with two requests at the same time;
 * - the clean-up keeps what a run that is not closed holds;
 * - a booking checks the free space and makes a test write.
 *
 * The real Apache or LiteSpeed is the live probe.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_Payload_File;
use AB_MCP_Protected_Store;
use AB_MCP_Store_Budget;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Sync_Fetcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProtectedStoreTest extends TestCase {

	/** The probe script, in the document root of both sites. */
	private const PROBE = 'ab-it-store-probe.php';

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	/** @var array<int,array{0:string,1:callable}> Filters a case added. */
	private $filters = array();

	public static function setUpBeforeClass(): void {
		foreach ( array( 'a', 'b' ) as $key ) {
			if ( ! copy( __DIR__ . '/support/store-probe.php', AB_MCP_IT_Sites::docroot( $key ) . '/' . self::PROBE ) ) {
				throw new RuntimeException( "Could not put the probe into site $key." );
			}
		}
	}

	public static function tearDownAfterClass(): void {
		foreach ( array( 'a', 'b' ) as $key ) {
			@unlink( AB_MCP_IT_Sites::docroot( $key ) . '/' . self::PROBE );
			self::apache( $key, false );
		}
		self::wipe();
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
	}

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		self::apache( 'a', false );
		self::apache( 'b', false );
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		wp_set_current_user( self::admin( 'a' ) );
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}
		$this->filters = array();
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		foreach ( array( self::root() . '/ab-mcp-store', self::root() ) as $dir ) {
			if ( is_dir( $dir ) && ! is_link( $dir ) ) {
				@chmod( $dir, 0755 );
			}
		}
		AB_MCP_Protected_Store::forget();
	}

	/* ------------------------------------------------------------ helpers */

	private static function root(): string {
		return (string) AB_MCP_IT_Sites::state()['root'];
	}

	private static function admin( string $key ): int {
		return (int) AB_MCP_IT_Sites::site( $key )['admin']['user_id'];
	}

	private static function uploads( string $key ): string {
		return AB_MCP_IT_Sites::docroot( $key ) . '/wp-content/uploads';
	}

	private static function outside( string $id ): string {
		return self::root() . '/ab-mcp-store/' . $id;
	}

	private static function in_uploads( string $key, string $id ): string {
		return self::uploads( $key ) . '/ab-mcp-store-' . substr( hash( 'sha256', 'ab-mcp-store|' . $id ), 0, 32 );
	}

	private static function side( string $folder, string $area, string $gid, string $id ): string {
		return $folder . '/' . $area . '/' . AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, $id, '' ) . '.json';
	}

	/** The stored id of a site, from its row. */
	private static function stored_id( string $key ): ?string {
		$row = AB_MCP_IT_Sites::option_row( $key, AB_MCP_Protected_Store::OPTION_ID );
		return null === $row ? null : (string) $row['option_value'];
	}

	/** The router answers as Apache with .htaccess for a site, or not. */
	private static function apache( string $key, bool $on ): void {
		$marker = self::root() . '/run/apache-' . basename( AB_MCP_IT_Sites::docroot( $key ) );
		if ( $on ) {
			touch( $marker );
		} elseif ( is_file( $marker ) ) {
			unlink( $marker );
		}
	}

	/** Remove what the store made: the parent beside WordPress and the folders and probes in both uploads. */
	private static function wipe(): void {
		$paths = array_merge( array( self::root() . '/ab-mcp-store' ), array_map( 'strval', (array) glob( self::root() . '/ab-mcp-store-test-*' ) ) );
		foreach ( array( 'a', 'b' ) as $key ) {
			foreach ( array_merge( (array) glob( self::uploads( $key ) . '/ab-mcp-store-*' ), (array) glob( self::uploads( $key ) . '/.ab-mcp-store-*' ), (array) glob( self::uploads( $key ) . '/ab-mcp-probe-*' ) ) as $path ) {
				$paths[] = (string) $path;
			}
			$paths[] = AB_MCP_IT_Sites::docroot( $key ) . '/wp-content/linked-store';
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

	/** Every file below a folder, relative, sorted. */
	private static function tree( string $dir ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out[] = substr( (string) $file, strlen( $dir ) + 1 );
		}
		sort( $out );
		return $out;
	}

	/** One request to the probe of a site. */
	private static function probe( string $key, array $body ): array {
		$body += array( 'user' => self::admin( $key ) );
		$res   = wp_remote_post(
			AB_MCP_IT_Sites::url( $key, '/' . self::PROBE ),
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( 'The probe of site ' . $key . ' did not answer: ' . $res->get_error_message() );
		}
		$out = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $out ) ) {
			throw new RuntimeException( 'The probe of site ' . $key . ' answered ' . wp_remote_retrieve_response_code( $res ) . ': ' . wp_remote_retrieve_body( $res ) );
		}
		return $out;
	}

	/**
	 * Requests to the probes at the same time (curl_multi), each answer in
	 * the order of the requests.
	 *
	 * @param array<int,array{0:string,1:array}> $requests Site and body.
	 * @return array[]
	 */
	private static function at_once( array $requests ): array {
		$multi   = curl_multi_init();
		$handles = array();
		foreach ( $requests as $i => $request ) {
			$body          = $request[1] + array( 'user' => self::admin( $request[0] ) );
			$handles[ $i ] = curl_init( AB_MCP_IT_Sites::url( $request[0], '/' . self::PROBE ) );
			curl_setopt_array(
				$handles[ $i ],
				array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => json_encode( $body ),
					CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 60,
				)
			);
			curl_multi_add_handle( $multi, $handles[ $i ] );
		}
		do {
			$status = curl_multi_exec( $multi, $running );
			if ( $running ) {
				curl_multi_select( $multi, 1.0 );
			}
		} while ( $running && CURLM_OK === $status );
		$out = array();
		foreach ( $handles as $i => $handle ) {
			$out[ $i ] = json_decode( (string) curl_multi_getcontent( $handle ), true );
			curl_multi_remove_handle( $multi, $handle );
		}
		curl_multi_close( $multi );
		return $out;
	}

	private function filter( string $hook, callable $fn ): void {
		add_filter( $hook, $fn );
		$this->filters[] = array( $hook, $fn );
	}

	/** Set the record of the last proof back by some seconds. */
	private static function age_record( int $seconds ): void {
		$rec = get_option( AB_MCP_Protected_Store::OPTION_PROOF );
		foreach ( array( 'outside', 'uploads' ) as $where ) {
			if ( isset( $rec['seen'][ $where ]['ok'] ) ) {
				$rec['seen'][ $where ]['ok']['start'] -= $seconds;
				$rec['seen'][ $where ]['ok']['end']   -= $seconds;
			}
			if ( isset( $rec['seen'][ $where ]['fail'] ) ) {
				$rec['seen'][ $where ]['fail']['end'] -= $seconds;
			}
		}
		update_option( AB_MCP_Protected_Store::OPTION_PROOF, $rec, false );
		AB_MCP_Protected_Store::forget();
	}

	/** A folder beside the store, in AB_IT_ROOT (removed by wipe()). */
	private static function elsewhere( string $name ): string {
		$dir = self::root() . '/ab-mcp-store-test-' . $name;
		mkdir( $dir, 0700 );
		return $dir;
	}

	/** The on-disk name of a group. */
	private static function group_dir( string $folder, string $area, string $gid, string $id ): string {
		return $folder . '/' . $area . '/' . AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, $id, '' );
	}

	/* -------------------------------------------------------------- place */

	public function testBesideWordPressAndOutsideTheWebRootTheStoreIsItsOwnFolder(): void {
		$proof = AB_MCP_Protected_Store::proof();
		self::assertTrue( $proof['available'], (string) $proof['message'] );
		self::assertSame( 'outside', $proof['where'] );
		$id = AB_MCP_Protected_Store::install_id();
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}\z/', $id );
		self::assertSame( self::outside( $id ), AB_MCP_Protected_Store::root() );

		$row = AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Protected_Store::OPTION_ID );
		self::assertSame( $id, $row['option_value'], 'One row holds the id.' );
		self::assertNotContains( $row['autoload'], wp_autoload_values_to_autoload(), 'Without autoload.' );

		$owner = json_decode( (string) file_get_contents( self::outside( $id ) . '/owner.json' ), true );
		self::assertSame( $id, $owner['install_id'] );
		self::assertSame( realpath( ABSPATH ), $owner['abspath'] );
		self::assertSame( hash( 'sha256', DB_NAME . '|' . AB_MCP_IT_Sites::prefix( 'a' ) ), $owner['database'] );
		self::assertSame( AB_MCP_IT_Sites::url( 'a' ), $owner['address'] );
		self::assertSame( array( '.htaccess', 'index.php', 'owner.json', 'store.lock', 'web.config' ), self::tree( self::outside( $id ) ), 'The binding was noted under the lock of the place.' );
		foreach ( AB_MCP_Protected_Store::AREAS as $area ) {
			self::assertDirectoryExists( self::outside( $id ) . '/' . $area );
		}
		self::assertSame( array(), (array) glob( self::uploads( 'a' ) . '/ab-mcp-store-*' ), 'Nothing in the uploads.' );

		AB_MCP_Protected_Store::forget();
		self::assertSame( $id, AB_MCP_Protected_Store::install_id(), 'The id stays.' );
		self::assertSame( $id, self::probe( 'a', array( 'action' => 'id' ) )['ok'], 'Also for a web request.' );
	}

	public function testUnderTheDocumentRootNothingIsMadeBesideWordPress(): void {
		// WordPress in a folder below the web root: the folder beside it is
		// in the web root.
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		$proof                    = AB_MCP_Protected_Store::proof();
		self::assertFalse( $proof['available'] );
		self::assertSame( 'docroot', $proof['findings']['outside'] );
		self::assertFileDoesNotExist( self::root() . '/ab-mcp-store', 'Nothing made in the web root.' );

		// ab-mcp-store a link into the web root of the site.
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		mkdir( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/linked-store' );
		symlink( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/linked-store', self::root() . '/ab-mcp-store' );
		AB_MCP_Protected_Store::forget();
		$proof = AB_MCP_Protected_Store::proof();
		self::assertFalse( $proof['available'] );
		self::assertSame( 'docroot', $proof['findings']['outside'], 'Through the link, by identity.' );
		self::assertSame( array(), self::tree( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/linked-store' ), 'Nothing made through the link.' );
	}

	public function testTheProductionSelfCheckAsksThroughOutboundWhichRefusesTheLocalSite(): void {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		$proof                    = AB_MCP_Protected_Store::proof();
		self::assertFalse( $proof['available'] );
		self::assertSame( 'self_unreachable', $proof['findings']['uploads'] );
		self::assertSame( array( 'error' => 'ab_mcp_outbound_local' ), $proof['detail'], 'AB_MCP_Outbound::get() refused the address of the site.' );
		self::assertSame( 'ab_mcp_store_unavailable', AB_MCP_Protected_Store::open( 'reports' )->get_error_code() );
	}

	public function testPhpBuiltInServerServesTheFolderSoThereIsNoProofAndNothingIsStored(): void {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		$fetcher                  = AB_MCP_Test_Sync_Fetcher::for_sites();
		$proof                    = AB_MCP_Protected_Store::proof( $fetcher );
		self::assertFalse( $proof['available'] );
		self::assertSame( 'served', $proof['findings']['uploads'] );
		self::assertSame( array( 'probes' => array( 200, 200, 200 ) ), $proof['detail'], '`php -S` reads no .htaccess.' );
		self::assertCount( 4, $fetcher->calls, 'The control file and the three test files.' );
		foreach ( $fetcher->calls as $call ) {
			self::assertArrayNotHasKey( 'cookies', $call['args'], 'Without a login.' );
			self::assertArrayNotHasKey( 'Authorization', $call['args']['headers'] );
			self::assertSame( 'no-cache, no-store, max-age=0', $call['args']['headers']['Cache-Control'] );
			self::assertMatchesRegularExpression( '/\?ab-mcp-nocache=[0-9a-f]{16}\z/', $call['url'], 'A fresh query past every cache.' );
		}
		$id     = AB_MCP_Protected_Store::install_id();
		$folder = self::in_uploads( 'a', $id );
		self::assertSame( array( '.htaccess', 'index.php', 'owner.json', 'web.config' ), self::tree( $folder ), 'The test files are gone again.' );
		self::assertSame( array(), (array) glob( self::uploads( 'a' ) . '/ab-mcp-probe-*' ), 'And the control file.' );
		$res = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 1 ) );
		self::assertSame( 'ab_mcp_store_unavailable', $res->get_error_code(), 'Without a proof nothing is stored.' );
		self::assertStringContainsString( 'the web server does not honour its .htaccess or web.config', $res->get_error_message() );
		self::assertSame( 'ab_mcp_store_unavailable', AB_MCP_Store_Budget::book( 1, 10 )->get_error_code() );
	}

	public function testAServerThatRefusesTheFolderGivesTheProofWhichIsMadeAgainAfterADayAndForAMissingGuard(): void {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( 'a', true );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		$proof   = AB_MCP_Protected_Store::proof( $fetcher );
		self::assertTrue( $proof['available'], (string) $proof['message'] );
		self::assertSame( 'uploads', $proof['where'] );
		self::assertSame( array( 'outside' => 'docroot' ), $proof['findings'] );
		self::assertCount( 4, $fetcher->calls );
		$id = AB_MCP_Protected_Store::install_id();
		self::assertSame( self::in_uploads( 'a', $id ), AB_MCP_Protected_Store::root() );
		$gid = AB_MCP_Protected_Store::open( 'reports' );
		self::assertIsString( $gid, 'With the proof the store is used.' );

		// A day is not over: the record holds, nothing is asked.
		self::age_record( AB_MCP_Protected_Store::RECHECK - 60 );
		self::assertTrue( AB_MCP_Protected_Store::proof( $fetcher )['available'] );
		self::assertCount( 4, $fetcher->calls );

		// A day later the check is made again.
		self::age_record( 120 );
		self::assertTrue( AB_MCP_Protected_Store::proof( $fetcher )['available'] );
		self::assertCount( 8, $fetcher->calls );

		// A missing guard is written again and the check made again.
		unlink( self::in_uploads( 'a', $id ) . '/.htaccess' );
		AB_MCP_Protected_Store::forget();
		self::assertTrue( AB_MCP_Protected_Store::proof( $fetcher )['available'] );
		self::assertCount( 12, $fetcher->calls );
		self::assertStringEqualsFile( self::in_uploads( 'a', $id ) . '/.htaccess', AB_MCP_Protected_Store::GUARDS['.htaccess'] );

		// The server stops refusing it: after a missing guard the check fails.
		self::apache( 'a', false );
		unlink( self::in_uploads( 'a', $id ) . '/web.config' );
		AB_MCP_Protected_Store::forget();
		$proof = AB_MCP_Protected_Store::proof( $fetcher );
		self::assertFalse( $proof['available'] );
		self::assertSame( 'served', $proof['findings']['uploads'] );
		self::assertCount( 16, $fetcher->calls );
		self::assertSame( 'ab_mcp_store_unavailable', AB_MCP_Protected_Store::open( 'reports' )->get_error_code() );
	}

	public function testWithoutTheDocumentRootOnlyAProofOfTheLastDayCounts(): void {
		self::assertTrue( AB_MCP_Protected_Store::proof()['available'] );
		unset( $_SERVER['DOCUMENT_ROOT'] );
		AB_MCP_Protected_Store::forget();
		$proof = AB_MCP_Protected_Store::proof();
		self::assertTrue( $proof['available'], 'The web proof of a moment ago.' );
		self::assertSame( 'outside', $proof['where'] );

		self::age_record( AB_MCP_Protected_Store::RECHECK );
		$proof = AB_MCP_Protected_Store::proof();
		self::assertFalse( $proof['available'] );
		self::assertSame( 'no_document_root', $proof['reason'] );
		self::assertStringContainsString( 'Call it again over the web', (string) $proof['message'] );

		// Never a place of its own: a fresh site without a proof stays without one.
		AB_MCP_IT_Sites::reset( 'a' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		self::assertSame( 'no_document_root', AB_MCP_Protected_Store::proof()['reason'] );
		self::assertFileDoesNotExist( self::root() . '/ab-mcp-store' );
		self::assertSame( array(), (array) glob( self::uploads( 'a' ) . '/ab-mcp-store-*' ) );
	}

	/* ----------------------------------------------- two installations */

	public function testTwoInstallationsBesideEachOtherUseAndCleanOnlyTheirOwnFolder(): void {
		$id_a = AB_MCP_Protected_Store::install_id();
		$gid  = AB_MCP_Protected_Store::open( 'reports' );
		self::assertIsString( $gid );
		$put = AB_MCP_Protected_Store::put( 'reports', $gid, 'report', 'of site A' );
		self::assertIsArray( $put );

		$b = self::probe( 'b', array( 'action' => 'proof' ) )['ok'];
		self::assertTrue( $b['available'] );
		self::assertSame( 'outside', $b['where'] );
		$id_b = self::stored_id( 'b' );
		self::assertNotSame( $id_a, $id_b );
		self::assertSame( self::outside( $id_b ), $b['root'], 'Site B: its own folder in the same parent.' );
		$owner_b = json_decode( (string) file_get_contents( self::outside( $id_b ) . '/owner.json' ), true );
		self::assertSame( realpath( AB_MCP_IT_Sites::docroot( 'b' ) ), $owner_b['abspath'] );
		self::assertSame( hash( 'sha256', DB_NAME . '|' . AB_MCP_IT_Sites::prefix( 'b' ) ), $owner_b['database'], 'Same database, another prefix.' );
		$gid_b = self::probe( 'b', array( 'action' => 'open', 'area' => 'reports' ) )['ok'];
		self::probe( 'b', array( 'action' => 'put', 'area' => 'reports', 'gid' => $gid_b, 'name' => 'report', 'payload' => base64_encode( 'of site B' ) ) );

		// Each sees only its own groups.
		self::assertSame( array( $gid ), array_column( AB_MCP_Protected_Store::groups( 'reports' ), 'gid' ) );
		self::assertSame( array( $gid_b ), array_column( self::probe( 'b', array( 'action' => 'groups', 'area' => 'reports' ) )['ok'], 'gid' ) );

		// The clean-up of B, two days later, removes B's report only.
		$swept = self::probe( 'b', array( 'action' => 'cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) )['ok'];
		self::assertSame( 1, $swept['removed'] );
		self::assertSame( array(), self::probe( 'b', array( 'action' => 'groups', 'area' => 'reports' ) )['ok'] );
		self::assertSame( 'of site A', AB_MCP_Protected_Store::get( 'reports', $gid, 'report', $put['sha256'] ) );
	}

	public function testACopyThatKeptTheIdGetsItsOwnAndLeavesTheOtherFolder(): void {
		$id_a = AB_MCP_Protected_Store::install_id();
		$gid  = AB_MCP_Protected_Store::open( 'reports' );
		$put  = AB_MCP_Protected_Store::put( 'reports', $gid, 'report', 'of site A' );
		$tree = self::tree( self::outside( $id_a ) );

		// Site B carries A's id, as a copy of A's database would.
		global $wpdb;
		$wpdb->insert( AB_MCP_IT_Sites::table( 'b', 'options' ), array( 'option_name' => AB_MCP_Protected_Store::OPTION_ID, 'option_value' => $id_a, 'autoload' => 'off' ) );

		// Its clean-up and its uninstall see no folder of theirs: A's
		// manifest is not B's.
		self::assertSame( array( 'removed' => 0, 'kept' => 0 ), self::probe( 'b', array( 'action' => 'cleanup', 'now' => time() + 30 * DAY_IN_SECONDS ) )['ok'] );
		self::assertSame( $tree, self::tree( self::outside( $id_a ) ) );

		// Its first proof gives it an id and a folder of its own.
		$b = self::probe( 'b', array( 'action' => 'proof' ) )['ok'];
		self::assertTrue( $b['available'], (string) $b['message'] );
		$id_b = self::stored_id( 'b' );
		self::assertNotSame( $id_a, $id_b, 'A new id for the copy.' );
		self::assertSame( self::outside( $id_b ), $b['root'] );
		self::assertSame( $tree, self::tree( self::outside( $id_a ) ), 'A\'s folder as it was.' );
		self::assertSame( $id_a, json_decode( (string) file_get_contents( self::outside( $id_a ) . '/owner.json' ), true )['install_id'] );
		AB_MCP_Protected_Store::forget();
		self::assertSame( $id_a, AB_MCP_Protected_Store::install_id(), 'A keeps its id.' );
		self::assertSame( 'of site A', AB_MCP_Protected_Store::get( 'reports', $gid, 'report', $put['sha256'] ) );
	}

	public function testUninstallRemovesTheOwnFolderOnlyWhereTheManifestIsOwn(): void {
		$id_a = AB_MCP_Protected_Store::install_id();
		$gid  = AB_MCP_Protected_Store::open( 'reports' );
		AB_MCP_Protected_Store::put( 'reports', $gid, 'report', 'of site A' );
		self::assertTrue( self::probe( 'b', array( 'action' => 'proof' ) )['ok']['available'] );
		$id_b   = self::stored_id( 'b' );
		$tree_b = self::tree( self::outside( $id_b ) );

		// A copy of A's database on B's site would carry A's id: its
		// uninstall leaves A's folder, whose manifest is not B's.
		global $wpdb;
		$wpdb->update( AB_MCP_IT_Sites::table( 'b', 'options' ), array( 'option_value' => $id_a ), array( 'option_name' => AB_MCP_Protected_Store::OPTION_ID ) );
		$tree_a = self::tree( self::outside( $id_a ) );
		self::uninstall( 'b' );
		self::assertSame( $tree_a, self::tree( self::outside( $id_a ) ), 'B\'s uninstall left A\'s folder.' );
		self::assertNull( self::stored_id( 'b' ) );

		AB_MCP_IT_Sites::reset( 'b' );
		$wpdb->insert( AB_MCP_IT_Sites::table( 'b', 'options' ), array( 'option_name' => AB_MCP_Protected_Store::OPTION_ID, 'option_value' => $id_b, 'autoload' => 'off' ) );
		self::uninstall( 'a' );
		self::assertFileDoesNotExist( self::outside( $id_a ), 'A\'s folder went with A.' );
		self::assertNull( self::stored_id( 'a' ) );
		self::assertNull( AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Protected_Store::OPTION_PROOF ) );
		self::assertSame( $tree_b, self::tree( self::outside( $id_b ) ), 'B\'s folder stays.' );
		self::assertDirectoryExists( self::root() . '/ab-mcp-store', 'The parent stays while B\'s folder is in it.' );

		self::uninstall( 'b' );
		self::assertFileDoesNotExist( self::root() . '/ab-mcp-store', 'The last one takes the empty parent.' );
	}

	private static function uninstall( string $key ): void {
		$command = array( PHP_BINARY, __DIR__ . '/bin/uninstall-site.php', AB_MCP_IT_Sites::state_file(), $key );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$out     = stream_get_contents( $pipes[1] );
		$err     = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $out . $err );
		self::assertSame( array( 'uninstalled' => true ), json_decode( (string) $out, true ), $out . $err );
	}

	/* ------------------------------------------------------ write, read */

	public function testAFileIsWrittenOnceThroughARenameAndReadOnlyWithItsHash(): void {
		$id      = AB_MCP_Protected_Store::install_id();
		$gid     = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 4 ) );
		$side    = self::side( self::outside( $id ), 'backups', $gid, $id );
		$before  = (string) file_get_contents( $side );
		$payload = str_repeat( 'post content ', 100 );
		link( $side, self::root() . '/run/sidecar-before' );
		try {
			$put = AB_MCP_Protected_Store::put( 'backups', $gid, 'post-12', $payload );
			self::assertSame( hash( 'sha256', $payload ), $put['sha256'] );
			self::assertSame( strlen( $payload ), $put['bytes'] );
			self::assertLessThan( $put['bytes'], $put['stored'], 'Compressed.' );
			self::assertSame( $before, file_get_contents( self::root() . '/run/sidecar-before' ), 'The sidecar is a new file, renamed into place: the old one is untouched.' );
			self::assertSame( strlen( $payload ), json_decode( (string) file_get_contents( $side ), true )['used'] );
		} finally {
			unlink( self::root() . '/run/sidecar-before' );
		}
		$dir = dirname( $side ) . '/' . basename( $side, '.json' );
		self::assertSame( array( 'post-12' ), self::tree( $dir ), 'No temp file left.' );
		self::assertSame( "\x1f\x8b", substr( (string) file_get_contents( $dir . '/post-12' ), 0, 2 ) );
		self::assertSame( array( 'post-12' => $put['stored'] ), AB_MCP_Protected_Store::files( 'backups', $gid ) );

		self::assertSame( $payload, AB_MCP_Protected_Store::get( 'backups', $gid, 'post-12', $put['sha256'] ) );
		self::assertSame( 'ab_mcp_store_corrupt', AB_MCP_Protected_Store::get( 'backups', $gid, 'post-12', hash( 'sha256', 'other' ) )->get_error_code() );
		file_put_contents( $dir . '/post-12', gzencode( 'changed by someone else' ) );
		self::assertSame( 'ab_mcp_store_corrupt', AB_MCP_Protected_Store::get( 'backups', $gid, 'post-12', $put['sha256'] )->get_error_code(), 'A changed file is not used.' );
		self::assertSame( 'ab_mcp_store_missing', AB_MCP_Protected_Store::get( 'backups', $gid, 'post-13', $put['sha256'] )->get_error_code() );

		self::assertSame( 'ab_mcp_store_exists', AB_MCP_Protected_Store::put( 'backups', $gid, 'post-12', 'again' )->get_error_code(), 'Written once.' );
		self::assertSame( 'gzip of changed by someone else', 'gzip of ' . gzdecode( (string) file_get_contents( $dir . '/post-12' ) ), 'Not written over.' );
		self::assertSame( 'ab_mcp_store_no_group', AB_MCP_Protected_Store::put( 'backups', str_repeat( 'e', 32 ), 'x', 'y' )->get_error_code() );
		self::assertSame( 'ab_mcp_store_no_group', AB_MCP_Protected_Store::put( 'reports', $gid, 'x', 'y' )->get_error_code(), 'A group belongs to its area.' );
	}

	public function testAPackageTakesNoMoreThanItDeclared(): void {
		$gid = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 10 ) );
		self::assertIsString( $gid );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, 'chunk-1', '123456' ) );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, 'chunk-2', '7890' ) );
		self::assertSame( 'ab_mcp_store_over_declared', AB_MCP_Protected_Store::put( 'in', $gid, 'chunk-3', 'x' )->get_error_code() );
		self::assertSame( array( 'chunk-1', 'chunk-2' ), array_keys( AB_MCP_Protected_Store::files( 'in', $gid ) ) );
		self::assertSame( 'ab_mcp_store_declare', AB_MCP_Protected_Store::open( 'out' )->get_error_code(), 'A package without a size is not opened.' );
	}

	public function testALinkOnTheWayIsNeverFollowed(): void {
		$id    = AB_MCP_Protected_Store::install_id();
		$store = (string) AB_MCP_Protected_Store::root();
		$gid   = str_repeat( 'a', 32 );

		// A sidecar and a group folder outside the store, and the area out
		// of the store a link to them.
		$outside = self::elsewhere( 'out' );
		$name    = basename( self::group_dir( $store, 'out', $gid, $id ) );
		file_put_contents( $outside . '/' . $name . '.json', json_encode( array( 'format' => AB_MCP_Protected_Store::FORMAT, 'gid' => $gid, 'area' => 'out', 'created' => time() - 30 * DAY_IN_SECONDS, 'declared' => 10 ) ) );
		mkdir( $outside . '/' . $name );
		file_put_contents( $outside . '/' . $name . '/victim', 'not the store\'s' );
		$before = self::tree( $outside );
		rmdir( $store . '/out' );
		symlink( $outside, $store . '/out' );

		self::assertFalse( AB_MCP_Protected_Store::remove( 'out', $gid ), 'Not removed through the linked area.' );
		AB_MCP_Protected_Store::cleanup( time() + 60 * DAY_IN_SECONDS );
		self::assertSame( $before, self::tree( $outside ), 'Nothing outside the store was touched.' );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::groups( 'out' )->get_error_code() );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::put( 'out', $gid, 'x', 'y' )->get_error_code() );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::get( 'out', $gid, 'victim', hash( 'sha256', "not the store's" ) )->get_error_code() );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::files( 'out', $gid )->get_error_code() );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::open( 'out', array( 'declared' => 1 ) )->get_error_code() );
		self::assertSame( $before, self::tree( $outside ) );

		// A link to another area of the store is no area either.
		unlink( $store . '/out' );
		symlink( $store . '/backups', $store . '/out' );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::open( 'out', array( 'declared' => 1 ) )->get_error_code() );
		self::assertSame( array(), self::tree( $store . '/backups' ), 'Nothing written into the other area.' );
		unlink( $store . '/out' );
		mkdir( $store . '/out', 0700 );

		// A group folder that is a link, and a file of a group that is one.
		$gid2   = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 1 ) );
		$group  = self::group_dir( $store, 'backups', $gid2, $id );
		$other  = self::elsewhere( 'group' );
		file_put_contents( $other . '/post-1', gzencode( 'x' ) );
		rmdir( $group );
		symlink( $other, $group );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::put( 'backups', $gid2, 'post-2', 'y' )->get_error_code() );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::get( 'backups', $gid2, 'post-1', hash( 'sha256', 'x' ) )->get_error_code() );
		self::assertFalse( AB_MCP_Protected_Store::remove( 'backups', $gid2 ) );
		AB_MCP_Protected_Store::cleanup( time() + 60 * DAY_IN_SECONDS );
		self::assertFalse( is_link( $group ) || file_exists( $group ), 'The clean-up took the link out of the store, itself.' );
		self::assertSame( array( 'post-1' ), self::tree( $other ), 'And left what it pointed to.' );

		$gid3  = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 1 ) );
		$group = self::group_dir( $store, 'backups', $gid3, $id );
		symlink( $other . '/post-1', $group . '/post-1' );
		self::assertSame( 'ab_mcp_store_link', AB_MCP_Protected_Store::get( 'backups', $gid3, 'post-1', hash( 'sha256', 'x' ) )->get_error_code() );
		self::assertSame( array(), AB_MCP_Protected_Store::files( 'backups', $gid3 ) );
	}

	public function testABoundPlaceStaysWhileItCanBeUsedAlsoWhenItIsEmpty(): void {
		// The uploads first: the folder beside WordPress is in the web root.
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( 'a', true );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( $fetcher )['where'] );
		$id      = AB_MCP_Protected_Store::install_id();
		$uploads = self::in_uploads( 'a', $id );
		$backup  = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 3 ) );
		$put     = AB_MCP_Protected_Store::put( 'backups', $backup, 'post-1', 'old content' );
		$package = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 10 ) );

		// The place beside WordPress could be used now; the uploads stay bound.
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( $fetcher )['where'] );
		self::assertSame( $uploads, AB_MCP_Protected_Store::root() );
		self::assertSame( 'old content', AB_MCP_Protected_Store::get( 'backups', $backup, 'post-1', $put['sha256'] ), 'The backup is found where it was written.' );
		self::assertSame( array( $package ), array_column( AB_MCP_Protected_Store::groups( 'in' ), 'gid' ), 'The packages are counted where they are.' );

		// Also with nothing left in them: a bound place is left only when it
		// cannot be used.
		self::assertTrue( AB_MCP_Protected_Store::remove( 'backups', $backup ) );
		self::assertTrue( AB_MCP_Protected_Store::remove( 'in', $package ) );
		AB_MCP_Protected_Store::forget();
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( $fetcher )['where'] );
		self::assertIsString( AB_MCP_Protected_Store::open( 'reports' ) );
		self::assertFileDoesNotExist( self::outside( $id ), 'Nothing made beside WordPress while the uploads are bound.' );
	}

	public function testAWriterWhosePlaceIsNoLongerBoundWritesNothing(): void {
		// This request (the test process) binds the uploads and keeps them.
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( 'a', true );
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( AB_MCP_Test_Sync_Fetcher::for_sites() )['where'] );
		$id      = AB_MCP_Protected_Store::install_id();
		$uploads = self::in_uploads( 'a', $id );
		$backup  = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 3 ) );
		$tree    = self::tree( $uploads );

		// Another request finds the uploads unusable (a guard file is gone,
		// and its own self-check through AB_MCP_Outbound cannot ask the local
		// site) and moves the store beside WordPress.
		unlink( $uploads . '/web.config' );
		$other = self::probe( 'a', array( 'action' => 'proof' ) )['ok'];
		self::assertSame( 'outside', $other['where'] );
		self::assertSame( array( 'uploads' => 'self_unreachable' ), $other['findings'] );

		// This request still has the uploads in hand: it writes nothing there.
		$tree = array_values( array_diff( self::tree( $uploads ), array( 'web.config' ) ) );
		foreach ( array(
			AB_MCP_Protected_Store::put( 'backups', $backup, 'post-1', 'x' ),
			AB_MCP_Protected_Store::open( 'reports' ),
			AB_MCP_Protected_Store::bind( 'backups', $backup, 4 ),
			AB_MCP_Store_Budget::book( 3, 10 ),
		) as $i => $res ) {
			self::assertInstanceOf( \WP_Error::class, $res, "call $i" );
			self::assertSame( 'ab_mcp_store_moved', $res->get_error_code(), "call $i" );
		}
		self::assertSame( $tree, array_values( array_diff( self::tree( $uploads ), array( 'web.config' ) ) ), 'Nothing written in the old place.' );

		// Asked again, it finds the store where it is now.
		AB_MCP_Protected_Store::forget();
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		self::assertSame( 'outside', AB_MCP_Protected_Store::proof()['where'] );
		self::assertIsString( AB_MCP_Protected_Store::open( 'reports' ) );
	}

	public function testAFailedCheckTakesTheProofBackAlsoForARequestWithoutTheDocumentRoot(): void {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( 'a', true );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( $fetcher )['where'] );
		$id = AB_MCP_Protected_Store::install_id();

		// Within the day the check is made again (a guard is gone) and fails.
		self::apache( 'a', false );
		unlink( self::in_uploads( 'a', $id ) . '/web.config' );
		AB_MCP_Protected_Store::forget();
		$proof = AB_MCP_Protected_Store::proof( $fetcher );
		self::assertFalse( $proof['available'] );
		self::assertSame( 'served', $proof['findings']['uploads'] );

		// The youngest finding counts: a request without the document root
		// does not use the uploads on the proof of before.
		unset( $_SERVER['DOCUMENT_ROOT'] );
		AB_MCP_Protected_Store::forget();
		self::assertSame( 'no_document_root', AB_MCP_Protected_Store::proof()['reason'] );

		// Only a check of a web request that passes gives the proof back.
		self::apache( 'a', true );
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::assertTrue( AB_MCP_Protected_Store::proof( $fetcher, true )['available'] );
		unset( $_SERVER['DOCUMENT_ROOT'] );
		AB_MCP_Protected_Store::forget();
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof()['where'] );
	}

	public function testABoundPlaceThatCannotBeUsedAnyMoreIsLeft(): void {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( 'a', true );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( $fetcher )['where'] );
		$id = AB_MCP_Protected_Store::install_id();
		self::assertIsString( AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 3 ) ) );

		// The web server serves the uploads folder now: it is left, groups or not.
		self::apache( 'a', false );
		unlink( self::in_uploads( 'a', $id ) . '/web.config' );
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		$proof = AB_MCP_Protected_Store::proof( $fetcher );
		self::assertSame( 'outside', $proof['where'] );
		self::assertSame( array( 'uploads' => 'served' ), $proof['findings'] );
	}

	/**
	 * Change the stored record of the proof on a connection of its own the
	 * moment before this request sends its own change of it: another request
	 * that writes between the read and the write.
	 *
	 * @param callable $change The stored value in, the other request's out.
	 */
	private function between_proof_write( callable $change ): void {
		$other  = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$table  = AB_MCP_IT_Sites::table( 'a', 'options' );
		$done   = false;
		$filter = static function ( $query ) use ( $other, $table, $change, &$done ) {
			if ( ! $done && 0 === strpos( (string) $query, 'UPDATE ' . $table . ' SET option_value' ) && false !== strpos( (string) $query, "'" . AB_MCP_Protected_Store::OPTION_PROOF . "'" ) ) {
				$done = true;
				$raw  = (string) $other->get_var( $other->prepare( "SELECT option_value FROM `$table` WHERE option_name = %s", AB_MCP_Protected_Store::OPTION_PROOF ) );
				$other->query( $other->prepare( "UPDATE `$table` SET option_value = %s WHERE option_name = %s", serialize( $change( unserialize( $raw ) ) ), AB_MCP_Protected_Store::OPTION_PROOF ) );
			}
			return $query;
		};
		$this->filter( 'query', $filter );
	}

	/** The record of the proof of site A, as it is in the table. */
	private static function stored_proof(): array {
		return (array) unserialize( (string) AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Protected_Store::OPTION_PROOF )['option_value'] );
	}

	public function testASuccessThatBeganBeforeAStoredFailureIsNotStored(): void {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( 'a', true );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( $fetcher )['where'] );
		// Ten seconds ago, so the check made again below is a new finding.
		self::age_record( 10 );
		$before = self::stored_proof();

		// This request (A) checks again and passes. Between its read of the
		// record and its write, another request (B) stores a failure it saw
		// after A's check had begun.
		$this->between_proof_write(
			static function ( array $rec ): array {
				$rec['rev']++;
				$rec['seen']['uploads']['fail'] = array(
					'end'    => microtime( true ),
					'reason' => 'served',
					'detail' => array( 'probes' => array( 200, 200, 200 ) ),
				);
				return $rec;
			}
		);
		AB_MCP_Protected_Store::forget();
		$calls = count( $fetcher->calls );
		$proof = AB_MCP_Protected_Store::proof( $fetcher, true );
		self::assertSame( $calls + 4, count( $fetcher->calls ), 'A made its check, and passed it.' );
		self::assertFalse( $proof['available'], 'A success that began before the failure ended counts for nothing.' );
		self::assertSame( 'served', $proof['findings']['uploads'] );
		$stored = self::stored_proof();
		self::assertSame( $before['rev'] + 1, $stored['rev'], 'A stored nothing: B\'s failure is the newest version.' );
		self::assertSame( $before['seen']['uploads']['ok'], $stored['seen']['uploads']['ok'], 'A\'s success is not in the record.' );
		self::assertSame( 'ab_mcp_store_unavailable', AB_MCP_Protected_Store::open( 'reports' )->get_error_code() );

		// Nor does a request without the document root use the uploads.
		unset( $_SERVER['DOCUMENT_ROOT'] );
		AB_MCP_Protected_Store::forget();
		self::assertSame( 'no_document_root', AB_MCP_Protected_Store::proof()['reason'] );
	}

	public function testAFailureSeenIsNeverLostToASuccessStoredMeanwhile(): void {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( 'a', true );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( $fetcher )['where'] );
		self::age_record( 10 );
		$before = self::stored_proof();
		$id     = AB_MCP_Protected_Store::install_id();

		// This request (B) checks again and sees the test files served.
		// Another request (A), whose check began before B's ended, stores
		// its success between B's read of the record and B's write.
		self::apache( 'a', false );
		$begun = microtime( true );
		$this->between_proof_write(
			static function ( array $rec ) use ( $begun ): array {
				$rec['rev']++;
				$rec['seen']['uploads']['ok'] = array(
					'start'  => $begun,
					'end'    => microtime( true ),
					'detail' => array( 'probes' => array( 403, 403, 403 ) ),
				);
				return $rec;
			}
		);
		AB_MCP_Protected_Store::forget();
		$proof = AB_MCP_Protected_Store::proof( $fetcher, true );
		self::assertFalse( $proof['available'], 'B\'s failure is not lost to A\'s success.' );
		self::assertSame( 'served', $proof['findings']['uploads'] );
		$stored = self::stored_proof();
		self::assertSame( $before['rev'] + 2, $stored['rev'], 'B read A\'s version again and stored its failure on top.' );
		self::assertSame( $begun, $stored['seen']['uploads']['ok']['start'], 'A\'s success is kept, and B\'s failure ended after it began.' );
		self::assertGreaterThan( $begun, $stored['seen']['uploads']['fail']['end'] );
		self::assertSame( 'ab_mcp_store_unproved', AB_MCP_Protected_Store::admitted( self::in_uploads( 'a', $id ) )->get_error_code(), 'No writer takes the uploads now.' );
		unset( $_SERVER['DOCUMENT_ROOT'] );
		AB_MCP_Protected_Store::forget();
		self::assertSame( 'no_document_root', AB_MCP_Protected_Store::proof()['reason'] );
	}

	public function testAProofKeptInARequestIsNoReleaseAfterAnotherRevokedIt(): void {
		$_SERVER['DOCUMENT_ROOT'] = self::root();
		self::apache( 'a', true );
		self::assertSame( 'uploads', AB_MCP_Protected_Store::proof( AB_MCP_Test_Sync_Fetcher::for_sites() )['where'] );
		$id      = AB_MCP_Protected_Store::install_id();
		$uploads = self::in_uploads( 'a', $id );
		$backup  = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 3 ) );
		$put     = AB_MCP_Protected_Store::put( 'backups', $backup, 'post-1', 'kept' );
		self::assertIsArray( $put );

		// Another request (site A over HTTP) checks again and fails: a guard
		// file is gone, its check through AB_MCP_Outbound cannot ask the
		// local site, and beside WordPress nothing can be made. It takes the
		// proof back and binds no other place.
		unlink( $uploads . '/web.config' );
		mkdir( self::root() . '/ab-mcp-store', 0555 );
		$other = self::probe( 'a', array( 'action' => 'proof' ) )['ok'];
		self::assertFalse( $other['available'] );
		self::assertSame(
			array(
				'uploads' => 'self_unreachable',
				'outside' => 'not_writable',
			),
			$other['findings']
		);
		self::assertSame( 'ab_mcp_store_unproved', AB_MCP_Protected_Store::admitted( $uploads )->get_error_code() );

		// This request still keeps its proof: no way of it takes it as a release.
		$tree = self::tree( $uploads );
		foreach ( array(
			'put'      => AB_MCP_Protected_Store::put( 'backups', $backup, 'post-2', 'x' ),
			'open'     => AB_MCP_Protected_Store::open( 'reports' ),
			'bind'     => AB_MCP_Protected_Store::bind( 'backups', $backup, 4 ),
			'book'     => AB_MCP_Store_Budget::book( 3, 10 ),
			'release'  => AB_MCP_Store_Budget::release( 3 ),
			'bookings' => AB_MCP_Store_Budget::bookings(),
			'get'      => AB_MCP_Protected_Store::get( 'backups', $backup, 'post-1', $put['sha256'] ),
			'groups'   => AB_MCP_Protected_Store::groups( 'backups' ),
			'files'    => AB_MCP_Protected_Store::files( 'backups', $backup ),
		) as $way => $res ) {
			self::assertInstanceOf( \WP_Error::class, $res, $way );
			self::assertSame( 'ab_mcp_store_unproved', $res->get_error_code(), $way );
		}
		self::assertSame( $tree, self::tree( $uploads ), 'Nothing written.' );
	}

	public function testAFileThatWasNotCountedStillCountsAgainstThePackage(): void {
		$id  = AB_MCP_Protected_Store::install_id();
		$gid = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 10 ) );
		// What a request that died between putting a file in place and
		// counting it leaves: a file the sidecar does not count.
		$dir = self::group_dir( (string) AB_MCP_Protected_Store::root(), 'in', $gid, $id );
		file_put_contents( $dir . '/chunk-0', gzencode( '12345678' ) );
		self::assertSame( 'ab_mcp_store_over_declared', AB_MCP_Protected_Store::put( 'in', $gid, 'chunk-1', '12345' )->get_error_code(), '8 held and 5 more would pass 10.' );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, 'chunk-1', '12' ) );
		self::assertSame( 10, AB_MCP_Protected_Store::groups( 'in' )[0]['used'], 'The sidecar counts what the files hold.' );
		self::assertSame( 'ab_mcp_store_over_declared', AB_MCP_Protected_Store::put( 'in', $gid, 'chunk-2', '1' )->get_error_code() );
	}

	/* ------------------------------------------------------------ budgets */

	public function testThreeExportsPerAccountHoldWithTwoRequestsAtOnce(): void {
		foreach ( array( 1, 2 ) as $n ) {
			self::assertIsString( AB_MCP_Protected_Store::open( 'out', array( 'declared' => 100, 'label' => 'export-' . $n ) ) );
		}
		$delay = array(
			'filter' => AB_MCP_Store_Budget::FILTER_EXPORTS,
			'us'     => 1500000,
		);
		$open  = array( 'action' => 'open', 'area' => 'out', 'meta' => array( 'declared' => 100 ), 'delay' => $delay );
		$both  = self::at_once( array( array( 'a', $open ), array( 'a', $open ) ) );
		$codes = array_map( static fn( $r ) => is_array( $r ) && isset( $r['ok'] ) ? 'ok' : ( $r['error'] ?? 'no answer' ), $both );
		sort( $codes );
		self::assertSame( array( 'ab_mcp_store_quota', 'ok' ), $codes, 'Counted under the lock: one passes, the other sees three.' );
		AB_MCP_Protected_Store::forget();
		$mine = array_filter( AB_MCP_Protected_Store::groups( 'out' ), static fn( array $g ): bool => self::admin( 'a' ) === $g['user_id'] );
		self::assertCount( 3, $mine );
	}

	public function testFivePackagesOnTheSiteHoldWithTwoRequestsAtOnce(): void {
		foreach ( range( 1, 4 ) as $n ) {
			self::assertIsString( AB_MCP_Protected_Store::open( 'in', array( 'declared' => 100 ) ) );
		}
		$open  = array(
			'action' => 'open',
			'area'   => 'in',
			'meta'   => array( 'declared' => 100 ),
			'delay'  => array(
				'filter' => AB_MCP_Store_Budget::FILTER_PACKAGES,
				'us'     => 1500000,
			),
		);
		$both  = self::at_once( array( array( 'a', $open ), array( 'a', $open ) ) );
		$codes = array_map( static fn( $r ) => is_array( $r ) && isset( $r['ok'] ) ? 'ok' : ( $r['error'] ?? 'no answer' ), $both );
		sort( $codes );
		self::assertSame( array( 'ab_mcp_store_quota', 'ok' ), $codes );
		AB_MCP_Protected_Store::forget();
		self::assertCount( 5, AB_MCP_Protected_Store::groups( 'in' ) );
		self::assertSame( 'ab_mcp_store_quota', AB_MCP_Protected_Store::open( 'out', array( 'declared' => 1 ) )->get_error_code(), 'Exports and imports together.' );
	}

	public function testTheBytesTheOpenPackagesDeclareAreLimited(): void {
		self::assertIsString( AB_MCP_Protected_Store::open( 'in', array( 'declared' => AB_MCP_Store_Budget::MAX_BYTES - 10 ) ) );
		self::assertIsString( AB_MCP_Protected_Store::open( 'out', array( 'declared' => 10 ) ), 'Exactly at the limit.' );
		self::assertSame( 'ab_mcp_store_quota', AB_MCP_Protected_Store::open( 'out', array( 'declared' => 1 ) )->get_error_code() );
	}

	/* ----------------------------------------------------------- clean-up */

	public function testTheCleanUpKeepsWhatARunThatIsNotClosedHolds(): void {
		global $wpdb;
		self::assertTrue( AB_MCP_Sync_Schema::available() );
		$runs = AB_MCP_Sync_Schema::table( 'runs' );
		foreach ( array( 7 => 'open', 8 => 'closed_done', 9 => 'interrupted' ) as $run => $state ) {
			$wpdb->insert( $runs, array( 'run_id' => $run, 'type' => 'push', 'state' => $state ) );
		}
		self::assertSame( array( 7, 9 ), AB_MCP_Sync_Schema::open_runs() );
		$id      = AB_MCP_Protected_Store::install_id();
		$open    = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 5, 'run_id' => 7 ) );
		$closed  = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 5, 'run_id' => 8 ) );
		$broken  = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 9 ) );
		$report  = AB_MCP_Protected_Store::open( 'reports' );
		$backup  = AB_MCP_Protected_Store::open( 'backups', array( 'run_id' => 8 ) );
		$present = static function ( string $area ): array {
			$gids = array_column( AB_MCP_Protected_Store::groups( $area ), 'gid' );
			sort( $gids );
			return $gids;
		};
		$sorted  = static function ( string ...$gids ): array {
			sort( $gids );
			return $gids;
		};

		// Two days later: packages and reports past their day, backups not.
		$swept = AB_MCP_Protected_Store::cleanup( time() + 2 * DAY_IN_SECONDS );
		self::assertSame( array( $open ), $present( 'in' ), 'The package of the open run stays, that of the closed run goes.' );
		self::assertSame( array(), $present( 'reports' ) );
		self::assertSame( $sorted( $broken, $backup ), $present( 'backups' ), 'Backups are kept seven days.' );
		self::assertSame( 2, $swept['removed'] );
		self::assertSame( 1, $swept['kept'], 'One group past its time, kept for its run.' );

		// Eight days later: the backups of the closed run go, those of the
		// interrupted run stay.
		AB_MCP_Protected_Store::cleanup( time() + 8 * DAY_IN_SECONDS );
		self::assertSame( array( $broken ), $present( 'backups' ) );
		self::assertSame( array( $open ), $present( 'in' ) );

		// While the runs cannot be read, a run's group stays.
		$wpdb->query( "DROP TABLE `$runs`" );
		AB_MCP_Sync_Schema::forget();
		self::assertNull( AB_MCP_Sync_Schema::open_runs() );
		AB_MCP_Protected_Store::cleanup( time() + 60 * DAY_IN_SECONDS );
		self::assertSame( array( $open ), $present( 'in' ) );
		self::assertSame( array( $broken ), $present( 'backups' ) );

		// Once its run is closed, it goes.
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_Sync_Schema::forget();
		AB_MCP_Protected_Store::forget();
		$wpdb->query( "INSERT INTO `$runs` ( run_id, type, state ) VALUES ( 7, 'push', 'closed_reverted' ), ( 9, 'push', 'closed_checked' )" );
		$wpdb->insert( AB_MCP_IT_Sites::table( 'a', 'options' ), array( 'option_name' => AB_MCP_Protected_Store::OPTION_ID, 'option_value' => $id, 'autoload' => 'off' ) );
		AB_MCP_Protected_Store::cleanup( time() + 60 * DAY_IN_SECONDS );
		self::assertSame( array(), $present( 'in' ) );
		self::assertSame( array(), $present( 'backups' ) );
	}

	/* ------------------------------------------------------------ booking */

	public function testABookingChecksTheFreeSpaceAndMakesATestWrite(): void {
		global $wpdb;
		$runs = AB_MCP_Sync_Schema::table( 'runs' );
		foreach ( array( 7 => 'open', 9 => 'open' ) as $run => $state ) {
			$wpdb->insert( $runs, array( 'run_id' => $run, 'type' => 'push', 'state' => $state ) );
		}
		$booked = AB_MCP_Store_Budget::book( 7, 1000 );
		self::assertIsArray( $booked, is_wp_error( $booked ) ? $booked->get_error_message() : '' );
		self::assertSame( 1000, $booked['bytes'] );
		self::assertSame( array( 7 ), array_keys( AB_MCP_Store_Budget::bookings() ) );
		$root = (string) AB_MCP_Protected_Store::root();
		self::assertSame( array(), (array) glob( $root . '/.ab-test-write-*' ), 'The test write is gone again.' );

		$free = (int) disk_free_space( $root );
		$res  = AB_MCP_Store_Budget::book( 9, $free );
		self::assertSame( 'ab_mcp_store_space', $res->get_error_code() );
		self::assertSame( 1000, $res->get_error_data()['others'], 'The other run\'s booking counts.' );
		self::assertSame( array( 7 ), array_keys( AB_MCP_Store_Budget::bookings() ) );

		// A run that closed gives its booking up at the next booking.
		$wpdb->update( $runs, array( 'state' => 'closed_done' ), array( 'run_id' => 7 ) );
		AB_MCP_Sync_Schema::forget();
		self::assertIsArray( AB_MCP_Store_Budget::book( 9, 10 ) );
		self::assertSame( array( 9 ), array_keys( AB_MCP_Store_Budget::bookings() ) );
		self::assertTrue( AB_MCP_Store_Budget::release( 9 ) );
		self::assertSame( array(), AB_MCP_Store_Budget::bookings() );

		// Where the test write fails, the run does not start. (A folder
		// without write permission stops no process of root.)
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			return;
		}
		chmod( $root, 0500 );
		try {
			$res = AB_MCP_Store_Budget::book( 9, 10 );
		} finally {
			chmod( $root, 0700 );
		}
		self::assertSame( 'ab_mcp_store_test_write', $res->get_error_code() );
		self::assertSame( array(), AB_MCP_Store_Budget::bookings() );
	}
}
