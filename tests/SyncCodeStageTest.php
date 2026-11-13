<?php
/**
 * Z8b branch 4, what needs no WordPress site: the line of a package of code
 * (AB_MCP_Sync_Code_Package::line(), lines_of(), unit_ok(), path_ok()), the
 * checks of the stage that are pure (AB_MCP_Sync_Code_Stage::parse_check()
 * with the decided limit of 1 MiB, O1; is_php()), the shared downloader
 * fetch_zip() (D2, V2) and the early refusals of from_org() (Z5, D1, D5),
 * each through the seam of the stage (AB_MCP_Test_Seam). End to end with two
 * sites: tests/integration/CodeStageTest.php.
 *
 * Fehlerarten of the calls to the outside of the stage (plan 8.3, branch 4),
 * where they are tested:
 * - HTTP (the download): Rückgabe false (WP_Error) and status 404 here
 *   (testTheDownloaderRefusesWhatIsNotAWholeZipOfItsLimit), Throwable and
 *   Zeitlimit as a WP_Error of the transport (WordPress catches the
 *   exceptions of Requests and gives WP_Error; a timeout is cURL error 28,
 *   the same WP_Error), halb geschrieben (a body cut at the limit) here,
 *   exit/Fatal in a process of its own in the integration suite;
 *   gleichzeitiger Schreiber: none, the file is made in a building folder of
 *   this request only.
 * - the tokenizer and the PHP command line: unavailable, failed and the
 *   memory here; a Throwable of the parser other than a CompileError is not
 *   caught: it ends the call, and the stage stays checking, never ready
 *   (integration).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Code_Package;
use AB_MCP_Sync_Code_Stage;
use AB_MCP_Test_Seam;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/sync-canon-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-code-stage.php';
require_once __DIR__ . '/integration/support/class-test-seam.php';

final class SyncCodeStageTest extends TestCase {

	/** @var string */
	private $dir = '';

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/ab-code-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/unit/inc', 0777, true );
		$this->dir = (string) realpath( $this->dir );
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			( $file->isDir() && ! $file->isLink() ) ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->dir );
	}

	private static function seam( object $calls ): object {
		return AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Stage::class, 'naht_sync_code_stage', $calls );
	}

	/* ----------------------------------------------- the lines of a package */

	public function testALineOfCodeIsOnePieceOfOneFileOfAUnitInTheOneSpellingOfTheCanon(): void {
		file_put_contents( $this->dir . '/unit/inc/a.php', '<?php // a' );
		$lines = array();
		$done  = AB_MCP_Sync_Code_Package::lines_of(
			$this->dir . '/unit',
			'folder',
			'plugin',
			'ab-it-code',
			array(
				'path'   => 'inc/a.php',
				'size'   => 10,
				'sha256' => hash( 'sha256', '<?php // a' ),
			),
			static function ( string $line ) use ( &$lines ) {
				$lines[] = $line;
				return true;
			}
		);
		self::assertTrue( $done );
		self::assertCount( 1, $lines );
		$read = AB_MCP_Sync_Code_Package::line( $lines[0] );
		self::assertSame( 'plugin', $read['kind'] );
		self::assertSame( 'ab-it-code', $read['ref'] );
		self::assertSame( 'inc/a.php', $read['path'] );
		self::assertSame( '<?php // a', $read['bytes'] );
		// Every field of its form, nothing else.
		$object = json_decode( $lines[0], true );
		foreach ( array( 'kind' => 'attachment', 'ref' => 'a/b', 'path' => '../x.php', 'size' => 12, 'pieces' => 2, 'piece' => 1, 'sha256' => 'x', 'data' => 'not base64!' ) as $field => $wrong ) {
			self::assertNull( AB_MCP_Sync_Code_Package::line( AB_MCP_Sync_Canon::encode( array( $field => $wrong ) + $object ) ), $field );
		}
		self::assertNull( AB_MCP_Sync_Code_Package::line( AB_MCP_Sync_Canon::encode( $object + array( 'more' => 1 ) ) ), 'A field more.' );
		self::assertNull( AB_MCP_Sync_Code_Package::line( str_replace( '"kind"', ' "kind"', $lines[0] ) ), 'Another spelling.' );
		// A file that changed since its manifest was read refuses the package.
		file_put_contents( $this->dir . '/unit/inc/a.php', '<?php // b' );
		self::assertSame(
			'ab_mcp_sync_code_changed',
			AB_MCP_Sync_Code_Package::lines_of( $this->dir . '/unit', 'folder', 'plugin', 'ab-it-code', array( 'path' => 'inc/a.php', 'size' => 10, 'sha256' => hash( 'sha256', '<?php // a' ) ), static fn() => true )->get_error_code()
		);
	}

	public function testAUnitAndAPathAreNamedAsTheSiteNamesThem(): void {
		foreach ( array( 'akismet', 'Mein Plugin', 'plügin', 'einzeln.php', str_repeat( 'a', 191 ) ) as $ref ) {
			self::assertTrue( AB_MCP_Sync_Code_Package::unit_ok( 'plugin', $ref ), $ref );
		}
		foreach ( array( '', '.', '..', 'a/b', 'a\\b', "a\nb", str_repeat( 'a', 192 ), "\xff" ) as $ref ) {
			self::assertFalse( AB_MCP_Sync_Code_Package::unit_ok( 'plugin', $ref ), var_export( $ref, true ) );
		}
		self::assertFalse( AB_MCP_Sync_Code_Package::unit_ok( 'mu_plugin', 'a' ), 'Plugins and themes only (plan E3).' );
		foreach ( array( 'a.php', 'inc/a.php', "inc/\xff.php", 'a b/c.php' ) as $path ) {
			self::assertTrue( AB_MCP_Sync_Code_Package::path_ok( $path ), $path );
		}
		foreach ( array( '', '/a.php', 'a//b.php', './a.php', 'a/../b.php', "a\0b", str_repeat( 'a', 4097 ) ) as $path ) {
			self::assertFalse( AB_MCP_Sync_Code_Package::path_ok( $path ), var_export( $path, true ) );
		}
	}

	/* ------------------------------------------------------- the parser */

	public function testTheParserChecksUpToOneMebibyteAndSkipsABytePastIt(): void {
		// O1 (decided 13.11.2026): 1 MiB is checked, 1 MiB and one byte is skipped.
		self::seam( new SyncCodeStageCalls() );
		$code = "<?php\n// " . str_repeat( 'x', AB_MCP_Sync_Code_Stage::PARSE_MAX_BYTES - 10 ) . "\n";
		self::assertSame( AB_MCP_Sync_Code_Stage::PARSE_MAX_BYTES, strlen( $code ) );
		self::assertSame( 'passed', AB_MCP_Sync_Code_Stage::parse_check( $code )['status'] );
		$more = AB_MCP_Sync_Code_Stage::parse_check( $code . ' ' );
		self::assertSame( 'skipped', $more['status'] );
		self::assertStringContainsString( (string) AB_MCP_Sync_Code_Stage::PARSE_MAX_BYTES, $more['why'] );
		self::assertSame( 1048576, AB_MCP_Sync_Code_Stage::PARSE_MAX_BYTES );
	}

	public function testTheParserIsSkippedWhereTheMemoryLeftIsLessThanAHundredTimesTheFile(): void {
		$calls         = new SyncCodeStageCalls();
		$code          = "<?php\n" . str_repeat( "\$a = 1;\n", 1000 );
		$calls->memory = array( 1000000, 1000000 - 100 * strlen( $code ) + 1 );
		self::seam( $calls );
		$read = AB_MCP_Sync_Code_Stage::parse_check( $code );
		self::assertSame( 'skipped', $read['status'] );
		self::assertStringContainsString( 'memory', $read['why'] );
		$calls->memory = array( 1000000, 1000000 - 100 * strlen( $code ) );
		self::assertSame( 'passed', AB_MCP_Sync_Code_Stage::parse_check( $code )['status'], 'Exactly a hundred times is enough.' );
		$calls->memory = array( -1, 999999999 );
		self::assertSame( 'passed', AB_MCP_Sync_Code_Stage::parse_check( $code )['status'], 'No limit: no skip.' );
	}

	public function testWithoutAMemoryLimitToReadOrBytesToReadTheParserPassesNothing(): void {
		// Unknown is never passed: no memory_limit to read, or no bytes, is
		// skipped (code:syntax_unchecked), never parsed as if it were known.
		$calls         = new SyncCodeStageCalls();
		$calls->memory = array( null, 0 );
		self::seam( $calls );
		$read = AB_MCP_Sync_Code_Stage::parse_check( "<?php\n\$a = 1;\n" );
		self::assertSame( 'skipped', $read['status'] );
		self::assertStringContainsString( 'memory_limit', $read['why'] );
		$calls->memory = array( -1, 0 );
		self::assertSame( 'skipped', AB_MCP_Sync_Code_Stage::parse_check( false )['status'] );
		self::assertSame( 'passed', AB_MCP_Sync_Code_Stage::parse_check( "<?php\n\$a = 1;\n" )['status'] );
	}

	public function testTheParserFindsASyntaxErrorAndNamesWhereNoTokenizerIs(): void {
		$calls = new SyncCodeStageCalls();
		self::seam( $calls );
		$failed = AB_MCP_Sync_Code_Stage::parse_check( "<?php\nfunction ( {\n" );
		self::assertSame( 'failed', $failed['status'] );
		self::assertSame( 2, $failed['line'] );
		self::assertNotSame( '', $failed['message'] );
		$calls->tokenizer = false;
		self::assertSame( array( 'status' => 'unavailable' ), AB_MCP_Sync_Code_Stage::parse_check( '<?php echo 1;' ) );
		// A break outside a loop parses (the parser does not compile): php -l finds it (F19).
		$calls->tokenizer = true;
		self::assertSame( 'passed', AB_MCP_Sync_Code_Stage::parse_check( "<?php\nbreak;\n" )['status'] );
	}

	public function testPhpRunsTheFilesOfItsExtensions(): void {
		foreach ( array( 'a.php', 'A.PHP', 'a.inc', 'a.phtml', 'a.php8', 'lang/de.l10n.php', 'a.php.', 'a.phar' ) as $name ) {
			self::assertTrue( AB_MCP_Sync_Code_Stage::is_php( $name ), $name );
		}
		foreach ( array( 'a.php.bak', 'a.js', 'readme.txt', 'php', 'a.phps' ) as $name ) {
			self::assertFalse( AB_MCP_Sync_Code_Stage::is_php( $name ), $name );
		}
	}

	/* ------------------------------------------------- the downloader (D2) */

	public function testTheDownloaderRefusesWhatIsNotAWholeZipOfItsLimit(): void {
		$calls = new SyncCodeStageCalls();
		self::seam( $calls );
		$to = $this->dir . '/package.zip';
		// Only https.
		self::assertSame( 'code:org_address', AB_MCP_Sync_Code_Stage::fetch_zip( 'http://downloads.wordpress.org/plugin/a.1.zip', $to, 100 )->get_error_code() );
		self::assertSame( array(), $calls->asked );
		// A ZIP within its limit; asked without redirect, the limit read plus one byte (V2).
		$calls->body = "PK\x03\x04" . str_repeat( 'z', 96 );
		self::assertTrue( AB_MCP_Sync_Code_Stage::fetch_zip( 'https://downloads.wordpress.org/plugin/a.1.zip', $to, 100 ) );
		self::assertSame( 0, $calls->asked[0][1]['redirection'] );
		self::assertSame( 101, $calls->asked[0][1]['limit_response_size'] );
		self::assertTrue( $calls->asked[0][1]['stream'] );
		self::assertSame( $to, $calls->asked[0][1]['filename'] );
		// One byte past the limit: refused, never used cut (V2: a body cut at the limit is no package).
		unlink( $to );
		$calls->body = "PK\x03\x04" . str_repeat( 'z', 97 );
		self::assertSame( 'code:org_too_large', AB_MCP_Sync_Code_Stage::fetch_zip( 'https://downloads.wordpress.org/plugin/a.1.zip', $to, 100 )->get_error_code() );
		// Empty, no ZIP, another status, an error of the transport (false, a timeout, a Throwable of Requests).
		foreach ( array( array( 200, '' ), array( 200, 'not a zip' ), array( 404, "PK\x03\x04" ), array( 301, "PK\x03\x04" ) ) as $case ) {
			@unlink( $to );
			$calls->status = $case[0];
			$calls->body   = $case[1];
			self::assertInstanceOf( WP_Error::class, AB_MCP_Sync_Code_Stage::fetch_zip( 'https://downloads.wordpress.org/plugin/a.1.zip', $to, 100 ), var_export( $case, true ) );
		}
		@unlink( $to );
		$calls->error = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$got          = AB_MCP_Sync_Code_Stage::fetch_zip( 'https://downloads.wordpress.org/plugin/a.1.zip', $to, 100 );
		self::assertSame( 'code:org_fetch', $got->get_error_code() );
		self::assertStringContainsString( 'timed out', $got->get_error_message() );
	}

	/* ------------------------------------- from_org(): its early refusals */

	private static function signed( array $over = array() ): array {
		return array_merge(
			array(
				'kind'       => 'plugin',
				'ref'        => 'ab-it-code',
				'form'       => 'folder',
				'hash'       => str_repeat( 'b', 64 ),
				'files'      => 1,
				'bytes'      => 3,
				'version'    => '1.1.0',
				'update_uri' => '',
				'entries'    => array( array( 'a.php', 3, str_repeat( 'a', 64 ) ) ),
			),
			$over
		);
	}

	public function testFromOrgFetchesNothingForAUnitOrAHeadOrAFileSystemItDoesNotTake(): void {
		$calls = new SyncCodeStageCalls();
		self::seam( $calls );
		$cases = array(
			'code:update_uri_unknown' => self::signed( array( 'update_uri' => null ) ),
			'code:update_uri'         => self::signed( array( 'update_uri' => 'example.com' ) ),
			'code:org_unit'           => self::signed( array( 'form' => 'file' ) ),
		);
		foreach ( array( null, 'trunk', '1.0/../x', '' ) as $version ) {
			$cases[ 'code:org_unit version ' . var_export( $version, true ) ] = self::signed( array( 'version' => $version ) );
		}
		foreach ( array( 'Mein Plugin', 'a_b', 'plügin' ) as $slug ) {
			$cases[ 'code:org_unit slug ' . $slug ] = self::signed( array( 'ref' => $slug ) );
		}
		foreach ( $cases as $code => $signed ) {
			$got = AB_MCP_Sync_Code_Stage::from_org( $signed, $this->dir );
			self::assertSame( strtok( $code, ' ' ), $got->get_error_code(), $code );
		}
		self::assertSame( array(), $calls->asked, 'Nothing asked of wordpress.org for any of them.' );
		// D5: not the file system method direct.
		$calls->method = 'ftpext';
		self::assertSame( 'code:file_mods', AB_MCP_Sync_Code_Stage::from_org( self::signed(), $this->dir )->get_error_code() );
		self::assertSame( array(), $calls->asked );
		// The exact address, https, the slug and the version of the staging site.
		$calls->method = 'direct';
		$calls->status = 404;
		self::assertSame( 'code:org_fetch', AB_MCP_Sync_Code_Stage::from_org( self::signed( array( 'update_uri' => 'w.org' ) ), $this->dir )->get_error_code() );
		self::assertSame( 'https://downloads.wordpress.org/plugin/ab-it-code.1.1.0.zip', $calls->asked[0][0] );
		AB_MCP_Sync_Code_Stage::from_org( self::signed( array( 'kind' => 'theme', 'ref' => 'twentyx' ) ), $this->dir );
		self::assertSame( 'https://downloads.wordpress.org/theme/twentyx.1.1.0.zip', $calls->asked[1][0] );
	}

	public function testAZipOfWordpressOrgIsUnpackedOnlyWithinItsSizeAndByUnzipFile(): void {
		$calls       = new SyncCodeStageCalls();
		$calls->body = "PK\x03\x04" . str_repeat( 'z', 96 );
		self::seam( $calls );
		$calls->unpacked = AB_MCP_Sync_Code_Stage::UNPACKED_MAX + 1;
		self::assertSame( 'code:org_too_large', AB_MCP_Sync_Code_Stage::from_org( self::signed(), $this->dir )->get_error_code() );
		self::assertSame( 0, $calls->unzipped, 'Not unpacked.' );
		$calls->unpacked = null;
		self::assertSame( 'code:org_too_large', AB_MCP_Sync_Code_Stage::from_org( self::signed(), $this->dir )->get_error_code(), 'A ZIP whose directory does not read.' );
		$calls->unpacked = AB_MCP_Sync_Code_Stage::UNPACKED_MAX;
		self::assertSame( 'code:org_unzip', AB_MCP_Sync_Code_Stage::from_org( self::signed(), $this->dir )->get_error_code(), 'unzip_file() refused it (here: the stand-in).' );
		self::assertSame( 1, $calls->unzipped );
	}

	public function testALineOfAUnitOfAVersionBeforeHasNoHostOfUpdatesAndFetchesNothing(): void {
		// D1: a line of the environment without update_uri (AlphaBridge before
		// branch 4 on the staging site) is taken, and its host is unknown.
		$line = array(
			'env'      => 'plugin',
			'ref'      => 'ab-it-code',
			'active'   => array(),
			'bytes'    => 3,
			'complete' => true,
			'files'    => 1,
			'form'     => 'folder',
			'hash'     => str_repeat( 'b', 64 ),
			'lock'     => null,
			'mains'    => array( 'ab-it-code.php' ),
			'mtime'    => null,
			'own'      => false,
			'sha256'   => null,
			'version'  => '1.1.0',
			'cause'    => null,
		);
		self::assertTrue( \AB_MCP_Sync_Env::shaped( $line ), 'A line of a version before is taken.' );
		self::assertNull( AB_MCP_Sync_Code_Stage::update_host( $line ) );
		self::assertTrue( \AB_MCP_Sync_Env::shaped( $line + array( 'update_uri' => '' ) ) );
		self::assertSame( '', AB_MCP_Sync_Code_Stage::update_host( $line + array( 'update_uri' => '' ) ) );
		self::assertFalse( \AB_MCP_Sync_Env::shaped( $line + array( 'update_uri' => 5 ) ), 'Of its type, where it is there.' );
		self::assertFalse( \AB_MCP_Sync_Env::shaped( array( 'update_uri' => '' ) + array_diff_key( $line, array( 'cause' => true ) ) ), 'Only update_uri may be missing.' );
	}

	public function testTheHostOfAUnitIsAnotherOneWhereAnyMainFileNamesOne(): void {
		$dir = $this->dir . '/unit';
		file_put_contents( $dir . '/a.php', "<?php\n/**\n * Plugin Name: A\n * Update URI: https://wordpress.org/plugins/a/\n */\n" );
		file_put_contents( $dir . '/b.php', "<?php\n/**\n * Plugin Name: B\n * Update URI: https://Updates.Example.com/b?key=geheim\n */\n" );
		file_put_contents( $dir . '/c.php', "<?php\n/**\n * Plugin Name: C\n */\n" );
		file_put_contents( $dir . '/d.php', "<?php\n/**\n * Plugin Name: D\n * Update URI: w.org/plugin/d\n */\n" );
		file_put_contents( $dir . '/e.php', "<?php\n/**\n * Plugin Name: E\n * Update URI: ///\n */\n" );
		$hosts = \AB_MCP_Sync_Env::update_hosts( $dir, array( 'a.php', 'b.php', 'c.php', 'd.php', 'e.php' ) );
		self::assertSame( '', $hosts['c.php'] );
		self::assertSame( 'w.org', $hosts['d.php'], 'No host read: what stands before the first "/".' );
		self::assertSame( '///', $hosts['e.php'], 'An address of signs only names no host of wordpress.org, never none.' );
		self::assertStringNotContainsString( 'geheim', implode( ' ', $hosts ), 'No more than the host goes into a line (a key of a licence stays out).' );
		$of = new \ReflectionMethod( \AB_MCP_Sync_Env::class, 'update_host_of' );
		self::assertSame( '', $of->invoke( null, array( '', '' ) ) );
		self::assertSame( 'wordpress.org', $of->invoke( null, array( 'wordpress.org', '' ) ) );
		self::assertSame( 'updates.example.com', $of->invoke( null, array( 'wordpress.org', 'updates.example.com' ) ), 'Another host of any main file is the host of the unit.' );
		self::assertSame( 'updates.example.com', $of->invoke( null, array( 'updates.example.com', 'w.org' ) ) );
		// The host of a unit only from the heads of all its main files: one
		// not read is unknown (null: from_org() refuses), never none.
		$unit = new \ReflectionMethod( \AB_MCP_Sync_Env::class, 'unit_host' );
		self::assertNull( $unit->invoke( null, array(), array( 'a.php' ) ) );
		self::assertNull( $unit->invoke( null, array( 'uris' => array( 'a.php' => 'wordpress.org' ) ), array( 'a.php', 'c.php' ) ) );
		self::assertSame( 'wordpress.org', $unit->invoke( null, array( 'uris' => $hosts ), array( 'a.php', 'c.php' ) ) );
		self::assertSame( 'updates.example.com', $unit->invoke( null, array( 'uris' => $hosts ), array( 'a.php', 'b.php' ) ) );
	}
}

/**
 * The calls of the stage for these tests: what they set, nothing else.
 */
final class SyncCodeStageCalls {

	/** @var bool */
	public $tokenizer = true;

	/** @var array{0:int|null,1:int} */
	public $memory = array( -1, 0 );

	/** @var string */
	public $method = 'direct';

	/** @var int */
	public $status = 200;

	/** @var string */
	public $body = '';

	/** @var WP_Error|null */
	public $error = null;

	/** @var array[] url, args */
	public $asked = array();

	/** @var int|null */
	public $unpacked = 0;

	/** @var int */
	public $unzipped = 0;

	public function unpacked_size( $zip ) {
		return $this->unpacked;
	}

	public function unzip( $zip, $to ) {
		++$this->unzipped;
		return new WP_Error( 'incompatible_archive', 'Incompatible Archive.' );
	}

	public function tokenizer(): bool {
		return $this->tokenizer;
	}

	public function parse_php( $code ): void {
		token_get_all( (string) $code, TOKEN_PARSE );
	}

	public function memory(): array {
		return $this->memory;
	}

	public function method(): string {
		return $this->method;
	}

	/** As WordPress does with stream and limit_response_size: the body cut at the limit, into the file. */
	public function get( $url, array $args ) {
		$this->asked[] = array( $url, $args );
		if ( null !== $this->error ) {
			return $this->error;
		}
		file_put_contents( $args['filename'], substr( $this->body, 0, $args['limit_response_size'] ) );
		return array(
			'response' => array( 'code' => $this->status ),
			'body'     => '',
		);
	}
}
