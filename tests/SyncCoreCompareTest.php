<?php
/**
 * The comparison of the core (AB_MCP_Sync_Core_Compare, Z8b branch 2;
 * plan 3.1 Z35, E18): the checksums of api.wordpress.org only over https,
 * only through AB_MCP_Outbound, never get_core_checksums(); the format of
 * the answer from a stored answer of the service (tests/fixtures/
 * core-checksums, read 12.11.2026, no request in a test); the core files of
 * a site checked against it; and no kind of the takeover that writes the
 * core.
 *
 * Kinds of fault of the request (through the seam naht_sync_core_compare):
 * return false and Throwable of the request (not reached), an answer cut
 * or not JSON (halb gelesen), another status, an answer of another form;
 * of the reading of the core files: a file missing or unreadable (counted
 * as missing or differing, never as equal), the time limit (no line, a
 * later call checks again). «Gleichzeitiger Schreiber» and «halb
 * geschrieben» do not apply: nothing is written, and a core file changed
 * while it is read differs from its checksum. exit/Fatal and the time
 * limit of PHP at the request, in a process of its own: the integration
 * case SyncManifestTest::testAnEndOfTheProcessOrItsTimeLimitWhileTheSeriesReadsLeavesNothing
 * (nothing left in the store, the next reading whole).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Core_Compare;
use AB_MCP_Sync_Env;
use AB_MCP_Sync_Kinds;
use AB_MCP_Test_Seam;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-env.php';
require_once __DIR__ . '/integration/support/class-test-abort.php';
require_once __DIR__ . '/integration/support/class-test-seam.php';

/**
 * A stand-in of the calls: answers and files given by the test.
 */
final class CoreCompareCalls {

	/** @var array|WP_Error|null The answer of get(). */
	public $answer = null;

	/** @var array<string,string|false> Path below ABSPATH => md5 (false: unreadable). */
	public $files = array();

	/** @var array[] The requests: url, args. */
	public $asked = array();

	public function get( $url, array $args ) {
		$this->asked[] = array( $url, $args );
		return $this->answer;
	}

	public function md5( $path ) {
		return $this->files[ substr( (string) $path, strlen( ABSPATH ) ) ] ?? false;
	}

	public function exists( $path ) {
		return array_key_exists( substr( (string) $path, strlen( ABSPATH ) ), $this->files );
	}
}

final class SyncCoreCompareTest extends TestCase {

	/** @var CoreCompareCalls */
	private $calls;

	protected function setUp(): void {
		ab_test_reset();
		$this->calls = new CoreCompareCalls();
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Core_Compare::class, 'naht_sync_core_compare', $this->calls );
		$GLOBALS['wp_version'] = '7.1.3';
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Env::$clock = null;
		AB_MCP_Sync_Kinds::reset();
		$GLOBALS['wp_version'] = '6.9.1';
		unset( $GLOBALS['wp_local_package'] );
	}

	private static function stored( string $name ): string {
		$body = file_get_contents( __DIR__ . '/fixtures/core-checksums/' . $name );
		if ( ! is_string( $body ) ) {
			throw new RuntimeException( "No stored answer $name." );
		}
		return $body;
	}

	private static function answer( string $body, int $status = 200 ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => 'OK',
			),
		);
	}

	/** The core files of the stored answer as this site would have them. */
	private function shipped(): array {
		$sums = json_decode( self::stored( '7.1.3-en_US.json' ), true )['checksums'];
		$this->calls->files = $sums;
		return $sums;
	}

	/* ---------------------------------------------------------- Z35 */

	public function testTheStoredAnswerHasTheFormThisClassReads(): void {
		$body = self::stored( '7.1.3-en_US.json' );
		self::assertSame( '78ff5ec132f655e0ed8411984d985bd3bd58271f782745d20200e28a8e571671', hash( 'sha256', $body ), 'The answer as it was read on 12.11.2026.' );
		$this->calls->answer = self::answer( $body );
		$sums                = AB_MCP_Sync_Core_Compare::checksums( '7.1.3', 'en_US' );
		self::assertIsArray( $sums );
		self::assertCount( 3782, $sums );
		self::assertSame( '1384496d40da0ad11c7df922e4814a15', $sums['wp-trackback.php'], 'checksums: path => md5 (32 hex).' );
		self::assertSame( 444, count( array_filter( array_keys( $sums ), static fn( $p ) => 0 === strpos( $p, 'wp-content/' ) ) ), 'The bundled plugins and themes are in the list too.' );
		$this->calls->answer = self::answer( self::stored( '0.0.1-en_US.json' ) );
		$none                = AB_MCP_Sync_Core_Compare::checksums( '0.0.1', 'en_US' );
		self::assertSame( 'ab_mcp_sync_core_unknown_version', $none->get_error_code(), '{"checksums":false} for a version it does not know.' );
	}

	public function testTheChecksumsAreAskedOverHttpsThroughTheOutboundWithoutARedirect(): void {
		$this->calls->answer = self::answer( self::stored( '7.1.3-en_US.json' ) );
		AB_MCP_Sync_Core_Compare::checksums( '7.1.3', 'de_CH' );
		self::assertCount( 1, $this->calls->asked );
		list( $url, $args ) = $this->calls->asked[0];
		self::assertSame( 'https://api.wordpress.org/core/checksums/1.0/?version=7.1.3&locale=de_CH', $url, 'Only https (Z35).' );
		self::assertSame( 0, $args['redirection'], 'No redirect followed: a redirect to http is an answer, not a request.' );
		self::assertSame( AB_MCP_Sync_Core_Compare::TIMEOUT, $args['timeout'] );
		$source = (string) file_get_contents( __DIR__ . '/../includes/sync/class-sync-core-compare.php' );
		self::assertStringContainsString( 'return AB_MCP_Outbound::get( (string) $url, $args );', $source, 'The real call is the outbound of AlphaBridge.' );
		foreach ( array( 'wp_remote_get', 'wp_safe_remote_get', 'wp_remote_request', 'download_url', 'http://' ) as $other ) {
			self::assertStringNotContainsString( $other, preg_replace( '#^\s*\*.*$#m', '', $source ), $other );
		}
	}

	public function testNoShippedCodeAsksForTheChecksumsThroughTheCore(): void {
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( __DIR__ . '/../includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( '.php' === substr( (string) $file, -4 ) && false !== stripos( (string) file_get_contents( (string) $file ), 'get_core_checksums(' ) ) {
				$found[] = basename( (string) $file );
			}
		}
		self::assertSame( array( 'class-sync-core-compare.php' ), $found, 'Named only in the head that says why not (it asks again over http).' );
		$source = (string) file_get_contents( __DIR__ . '/../includes/sync/class-sync-core-compare.php' );
		self::assertSame( 1, substr_count( $source, 'get_core_checksums()' ) );
		self::assertSame( 0, preg_match( '/^[^*]*get_core_checksums\(/m', $source ), 'Never called.' );
	}

	public function testAnAnswerThatIsNotTheChecksumsIsRefused(): void {
		$cases = array(
			'not reached'         => array( new WP_Error( 'http_request_failed', 'down' ), 'unreachable' ),
			'no answer'           => array( null, 'unreachable' ),
			'another status'      => array( self::answer( '{}', 500 ), 'unreachable' ),
			'a redirect'          => array( self::answer( '', 301 ), 'unreachable' ),
			'empty'               => array( self::answer( '' ), 'unreachable' ),
			'cut (halb gelesen)'  => array( self::answer( substr( self::stored( '7.1.3-en_US.json' ), 0, 1000 ) ), 'answer' ),
			'no checksums'        => array( self::answer( '{"other":1}' ), 'answer' ),
			'checksums empty'     => array( self::answer( '{"checksums":{}}' ), 'answer' ),
			'checksums a list'    => array( self::answer( '{"checksums":["a"]}' ), 'answer' ),
			'a path upwards'      => array( self::answer( '{"checksums":{"../wp-config.php":"' . str_repeat( 'a', 32 ) . '"}}' ), 'answer' ),
			'an absolute path'    => array( self::answer( '{"checksums":{"/etc/passwd":"' . str_repeat( 'a', 32 ) . '"}}' ), 'answer' ),
			'a backslash'         => array( self::answer( '{"checksums":{"wp-admin\\\\x.php":"' . str_repeat( 'a', 32 ) . '"}}' ), 'answer' ),
			'no md5'              => array( self::answer( '{"checksums":{"index.php":"' . str_repeat( 'a', 40 ) . '"}}' ), 'answer' ),
			'md5 in capitals'     => array( self::answer( '{"checksums":{"index.php":"' . str_repeat( 'A', 32 ) . '"}}' ), 'answer' ),
			'too large'           => array( self::answer( str_repeat( ' ', AB_MCP_Sync_Core_Compare::MAX_BODY + 1 ) ), 'unreachable' ),
		);
		foreach ( $cases as $case => list( $answer, $cause ) ) {
			$this->calls->answer = $answer;
			$sums                = AB_MCP_Sync_Core_Compare::checksums( '7.1.3', 'en_US' );
			self::assertInstanceOf( WP_Error::class, $sums, $case );
			self::assertSame( 'ab_mcp_sync_core_' . $cause, $sums->get_error_code(), $case );
		}
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Core_Compare::class, 'naht_sync_core_compare', $this->calls, array( array( 'method' => 'get', 'kind' => 'throw', 'message' => 'socket' ) ) );
		$this->expectException( \RuntimeException::class );
		AB_MCP_Sync_Core_Compare::checksums( '7.1.3', 'en_US' );
	}

	/* --------------------------------------------- the check of a site */

	public function testTheCoreFilesAsShippedAreEqualWithoutWpContent(): void {
		$this->shipped();
		$this->calls->answer = self::answer( self::stored( '7.1.3-en_US.json' ) );
		$check               = AB_MCP_Sync_Core_Compare::check( INF );
		self::assertSame( array( 'cause' => null, 'checked' => 3338, 'complete' => true, 'differs' => 0, 'missing' => 0 ), $check );
		self::assertTrue( AB_MCP_Sync_Env::shaped( array( 'env' => 'core_files', 'ref' => 'files' ) + $check ) );
	}

	public function testAChangedOrMissingCoreFileDiffersByItsCount(): void {
		$sums = $this->shipped();
		$this->calls->files['wp-includes/version.php'] = str_repeat( 'f', 32 );
		$this->calls->files['wp-login.php']            = false;
		unset( $this->calls->files['xmlrpc.php'] );
		$this->calls->files['wp-content/plugins/hello.php'] = str_repeat( 'f', 32 );
		$this->calls->answer = self::answer( self::stored( '7.1.3-en_US.json' ) );
		$check               = AB_MCP_Sync_Core_Compare::check( INF );
		self::assertSame( array( true, 3338, 2, 1 ), array( $check['complete'], $check['checked'], $check['differs'], $check['missing'] ), 'A file changed (F17) or unreadable differs; one gone is missing; wp-content is no core.' );
		self::assertArrayHasKey( 'wp-includes/version.php', $sums );
	}

	public function testASiteWhoseChecksumsAreNotThereIsNotComparedWithItsCause(): void {
		$this->calls->answer = new WP_Error( 'http_request_failed', 'down' );
		self::assertSame( array( 'cause' => 'unreachable', 'checked' => 0, 'complete' => false ), array_intersect_key( AB_MCP_Sync_Core_Compare::check( INF ), array_flip( array( 'cause', 'complete', 'checked' ) ) ) );
		$GLOBALS['wp_version'] = 'x';
		self::assertSame( 'no_version', AB_MCP_Sync_Core_Compare::check( INF )['cause'] );
		self::assertSame( 'answer', AB_MCP_Sync_Core_Compare::cause( 'ab_mcp_sync_core_other' ), 'A cause it does not know: answer (fail closed).' );
	}

	public function testTheLanguageOfThePackageOfWordPressIsAsked(): void {
		$this->shipped();
		$this->calls->answer          = self::answer( self::stored( '7.1.3-en_US.json' ) );
		$GLOBALS['wp_local_package'] = 'de_CH';
		AB_MCP_Sync_Core_Compare::check( INF );
		self::assertStringEndsWith( 'version=7.1.3&locale=de_CH', $this->calls->asked[0][0] );
	}

	public function testTheTimeOfTheCallOverLeavesNoLine(): void {
		$this->shipped();
		$this->calls->answer    = self::answer( self::stored( '7.1.3-en_US.json' ) );
		AB_MCP_Sync_Env::$clock = static fn() => 1000.0;
		self::assertNull( AB_MCP_Sync_Core_Compare::check( 999.0 ), 'A later call checks again (Zeitlimit).' );
	}

	/* ------------------------------------- no kind that writes the core */

	public function testNoKindCanClaimTheCore(): void {
		foreach ( AB_MCP_Sync_Kinds::OWNERSHIP as $key => $entry ) {
			if ( 0 === strpos( $key, 'core:' ) ) {
				self::assertNull( $entry['kind'], $key );
				self::assertSame( 'Z8', $entry['stage'], $key . ': never.' );
			}
		}
		$answer = AB_MCP_Sync_Kinds::core( 'files' );
		self::assertSame( array( 'core:*', 'locked', null ), array( $answer['key'], $answer['status'], $answer['kind'] ) );
		$kind = new class() implements \AB_MCP_Sync_Kind {
			public function kind() { return 'core_writer'; }
			public function phase() { return 2; }
			public function responsibility() { return array( 'core:*' ); }
			public function inventory( $source, $cursor ) { return array(); }
			public function canonical( $object ) { return array(); }
			public function export( array $refs ) { return array(); }
			public function rights( $op, $ref ) { return true; }
			public function core_effects( $op ) { return array(); }
			public function fields() { return array(); }
			public function parts( array $payload ) { return array(); }
			public function backup( array $part ) { return array(); }
			public function write( array $part ) { return true; }
			public function read_back( array $part ) { return ''; }
			public function restore_part( array $part ) { return true; }
			public function post_write( array $parts ) { return true; }
			public function verify( array $pair ) { return true; }
		};
		$done = AB_MCP_Sync_Kinds::register( $kind );
		self::assertInstanceOf( WP_Error::class, $done, 'A kind that claims the core is refused (Z35).' );
		self::assertNull( AB_MCP_Sync_Kinds::get( 'core_writer' ) );
	}
}
