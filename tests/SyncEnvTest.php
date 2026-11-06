<?php
/**
 * The environment of the report as far as a test without WordPress shows it
 * (AB_MCP_Sync_Env; Z8 decision 14, «Lesende Bestandsaufnahme der übrigen
 * Arten»; branch 7):
 *
 * - the walk of a root of files: depth first in one order, counted and
 *   summed, a link not followed, the attached paths apart, the top level a
 *   root leaves out, a folder that cannot be read, and the same counts over
 *   parts as in one go;
 * - the WordPress block of the .htaccess, the schema of a table without
 *   prefix and AUTO_INCREMENT, the value of a constant (a text that is not
 *   plain by its SHA-256 only), and no constant that holds a secret
 *   (NEVER, a name the redactor or ab_mcp_redact_keys redacts);
 * - the structure of a line of a package (each kind, each field, each type,
 *   the lists of refs);
 * - the comparison: only a must-use plugin, a drop-in and group U equal or
 *   differ by what was read; what is counted or summed is counted, contents
 *   not compared, with its stage, never equal; one side only, not compared
 *   with why; each lock with its reason and stage; group U and the drop-ins
 *   changed at the host; no hash in an entry.
 *
 * What WordPress gives (plugins, themes, tables, the report over two sites):
 * tests/integration/SyncEnvTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Redactor;
use AB_MCP_Sync_Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-env.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';

final class SyncEnvTest extends TestCase {

	/** @var string A folder of this test. */
	private $dir = '';

	protected function setUp(): void {
		ab_test_reset();
		$this->dir = sys_get_temp_dir() . '/ab-env-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		AB_MCP_Sync_Env::$clock = null;
		unset( $GLOBALS['ab_test_filters']['ab_mcp_redact_keys'] );
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			self::remove( $this->dir );
		}
	}

	private static function remove( string $path ): void {
		if ( 0 !== strpos( $path, sys_get_temp_dir() . '/ab-env-' ) ) {
			throw new RuntimeException( "Will not remove $path." );
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		chmod( $path, 0755 );
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove( $path . '/' . $entry );
		}
		rmdir( $path );
	}

	private function file( string $rel, string $bytes ): void {
		$path = $this->dir . '/' . $rel;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		file_put_contents( $path, $bytes );
	}

	private static function acc(): array {
		return array(
			'after'    => null,
			'attached' => 0,
			'bytes'    => 0,
			'files'    => 0,
			'skipped'  => 0,
		);
	}

	private static function none(): callable {
		return static fn() => false;
	}

	/* ------------------------------------------------------------ walk */

	public function testTheOrderOfTheWalkIsDepthFirstByStrcmpOfEachPart(): void {
		self::assertSame( -1, AB_MCP_Sync_Env::order( 'a/x', 'a-b' ), 'All of a before a-b: the parts, not the text («/» sorts after «-»).' );
		self::assertSame( 1, AB_MCP_Sync_Env::order( 'a-b', 'a/x' ) );
		self::assertSame( -1, AB_MCP_Sync_Env::order( 'a', 'a/x' ), 'A folder before what is in it.' );
		self::assertSame( 1, AB_MCP_Sync_Env::order( 'a/x', 'a' ) );
		self::assertSame( 0, AB_MCP_Sync_Env::order( 'a/b/c', 'a/b/c' ) );
		self::assertSame( -1, AB_MCP_Sync_Env::order( 'B', 'a' ), 'strcmp: upper case first.' );
		self::assertSame( -1, AB_MCP_Sync_Env::order( 'a/b/c', 'a/c' ) );
		self::assertSame( 1, AB_MCP_Sync_Env::order( 'b', 'a/z/z' ) );
	}

	public function testAWalkCountsAndSumsTheFilesOfARoot(): void {
		$this->file( 'a.txt', 'abc' );
		$this->file( 'sub/b.txt', 'hello' );
		$this->file( 'sub/deep/c.txt', '' );
		$acc = self::acc();
		self::assertSame( 'done', AB_MCP_Sync_Env::walk( $this->dir, null, $acc, PHP_INT_MAX, self::none() ) );
		self::assertSame( array( 3, 8, 0, 0 ), array( $acc['files'], $acc['bytes'], $acc['skipped'], $acc['attached'] ) );
		self::assertSame( 'sub', $acc['after'], 'The last path it counted to its end: the folder sub with all in it.' );
	}

	public function testAWalkFollowsNoLinkAndCountsItAsSkipped(): void {
		$this->file( 'a.txt', 'abc' );
		$this->file( 'outside/big.txt', str_repeat( 'x', 100 ) );
		mkdir( $this->dir . '/root' );
		symlink( $this->dir . '/outside', $this->dir . '/root/linked-dir' );
		symlink( $this->dir . '/a.txt', $this->dir . '/root/linked-file' );
		file_put_contents( $this->dir . '/root/own.txt', 'own' );
		$acc = self::acc();
		self::assertSame( 'done', AB_MCP_Sync_Env::walk( $this->dir . '/root', null, $acc, PHP_INT_MAX, self::none() ) );
		self::assertSame( array( 1, 3, 2 ), array( $acc['files'], $acc['bytes'], $acc['skipped'] ), 'A link to a folder or a file is neither followed nor summed.' );
	}

	public function testAWalkCountsTheAttachedPathsApart(): void {
		$this->file( '2026/10/photo.jpg', 'jpeg' );
		$this->file( '2026/10/photo-150x150.jpg', 'th' );
		$this->file( '2026/10/loose.pdf', 'pdf!' );
		$acc   = self::acc();
		$known = array(
			'2026/10/photo.jpg'         => true,
			'2026/10/photo-150x150.jpg' => true,
		);
		self::assertSame( 'done', AB_MCP_Sync_Env::walk( $this->dir, null, $acc, PHP_INT_MAX, self::none(), $known ) );
		self::assertSame( array( 1, 4, 2 ), array( $acc['files'], $acc['bytes'], $acc['attached'] ), 'Outside the file lists of attachments: the loose file only.' );
	}

	public function testAWalkLeavesOutWhatTheRootSkipsAtItsTopLevelOnly(): void {
		$this->file( 'fonts/a.woff2', 'font' );
		$this->file( 'keep/fonts/b.txt', 'kept' );
		$acc  = self::acc();
		$skip = static fn( string $dir, string $name ): bool => 'fonts' === $name;
		self::assertSame( 'done', AB_MCP_Sync_Env::walk( $this->dir, null, $acc, PHP_INT_MAX, $skip ) );
		self::assertSame( array( 1, 4 ), array( $acc['files'], $acc['bytes'] ), 'A folder fonts deeper down is counted.' );
	}

	public function testAWalkOverAFolderThatCannotBeReadIsUnreadable(): void {
		if ( 0 === posix_geteuid() ) {
			self::markTestSkipped( 'root reads every folder.' );
		}
		$this->file( 'open/a.txt', 'a' );
		$this->file( 'shut/b.txt', 'b' );
		chmod( $this->dir . '/shut', 0000 );
		$acc = self::acc();
		self::assertSame( 'unreadable', AB_MCP_Sync_Env::walk( $this->dir, null, $acc, PHP_INT_MAX, self::none() ) );
		$acc = self::acc();
		self::assertSame( 'unreadable', AB_MCP_Sync_Env::walk( $this->dir . '/missing', null, $acc, PHP_INT_MAX, self::none() ), 'A root that is not there to be read either.' );
	}

	public function testAWalkStoppedByTheClockGoesOnAfterTheLastPathWithTheSameCounts(): void {
		$expected_bytes = 0;
		foreach ( array( 'a', 'a-b', 'b', 'c/d', 'c/e/f', 'z' ) as $folder ) {
			for ( $i = 0; $i < 30; $i++ ) {
				$bytes = str_repeat( 'x', $i );
				$this->file( $folder . '/f' . sprintf( '%02d', $i ) . '.txt', $bytes );
				$expected_bytes += $i;
			}
		}
		$whole = self::acc();
		self::assertSame( 'done', AB_MCP_Sync_Env::walk( $this->dir, null, $whole, PHP_INT_MAX, self::none() ) );
		self::assertSame( array( 180, $expected_bytes ), array( $whole['files'], $whole['bytes'] ) );

		// Each look at the clock is a second later; a part has two.
		$now                    = 0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$now ) {
			return (float) ++$now;
		};
		$acc   = self::acc();
		$parts = 0;
		$seen  = array();
		do {
			$deadline = $now + 2;
			$walked   = AB_MCP_Sync_Env::walk( $this->dir, $acc['after'], $acc, $deadline, self::none() );
			$seen[]   = $acc['after'];
			++$parts;
		} while ( 'stopped' === $walked && $parts < 100 );
		self::assertSame( 'done', $walked );
		self::assertGreaterThan( 1, $parts, 'Stopped by the clock at least once.' );
		self::assertSame( array( $whole['files'], $whole['bytes'], $whole['after'] ), array( $acc['files'], $acc['bytes'], $acc['after'] ), 'Over parts, each file counted once.' );
		self::assertSame( count( $seen ), count( array_unique( $seen ) ), 'Every part moved on.' );
	}

	public function testAWalkOverFoldersWithoutAFileMovesOnInEveryPart(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			mkdir( $this->dir . sprintf( '/d%03d', $i ) );
		}
		$deep = $this->dir . '/e';
		for ( $i = 0; $i < 100; $i++ ) {
			$deep .= '/x';
		}
		mkdir( $deep, 0755, true );
		file_put_contents( $deep . '/f.txt', 'f' );
		// The clock always past the end of the part.
		AB_MCP_Sync_Env::$clock = static fn(): float => 10.0;
		$acc   = self::acc();
		$parts = 0;
		do {
			$walked = AB_MCP_Sync_Env::walk( $this->dir, $acc['after'], $acc, 1.0, self::none() );
			++$parts;
		} while ( 'stopped' === $walked && $parts < 50 );
		self::assertSame( 'done', $walked );
		self::assertGreaterThan( 2, $parts, 'A folder counted to its end is where the next part goes on.' );
		self::assertSame( array( 1, 1 ), array( $acc['files'], $acc['bytes'] ) );
	}

	public function testAWalkOverOneLargeFolderMovesOnInEveryPart(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$this->file( sprintf( 'big/f%03d.txt', $i ), 'x' );
		}
		AB_MCP_Sync_Env::$clock = static fn(): float => 10.0;
		$acc   = self::acc();
		$parts = 0;
		do {
			$walked = AB_MCP_Sync_Env::walk( $this->dir, $acc['after'], $acc, 1.0, self::none() );
			++$parts;
		} while ( 'stopped' === $walked && $parts < 50 );
		self::assertSame( 'done', $walked );
		self::assertGreaterThan( 2, $parts, 'The last file counted is where the next part goes on, also within one folder.' );
		self::assertSame( array( 200, 200 ), array( $acc['files'], $acc['bytes'] ) );
	}

	/* ----------------------------------------------------------- the host */

	public function testTheWordPressBlockOfTheHtaccessIsTheLinesBetweenItsMarkers(): void {
		$text = "# BEGIN Other\nRewriteRule x\n# END Other\n# BEGIN WordPress\r\nRewriteEngine On\r\nRewriteBase /\r\n# END WordPress\nafter\n";
		self::assertSame( "RewriteEngine On\nRewriteBase /", AB_MCP_Sync_Env::block( $text ), 'Without the ends of the lines, without the markers and what is around.' );
		self::assertNull( AB_MCP_Sync_Env::block( "RewriteEngine On\n" ), 'No block.' );
		self::assertNull( AB_MCP_Sync_Env::block( "# BEGIN WordPress\nRewriteEngine On\n" ), 'A block without its end is none.' );
		self::assertSame( '', AB_MCP_Sync_Env::block( "# BEGIN WordPress\n# END WordPress\n" ), 'An empty block is one.' );
		self::assertSame( 'a', AB_MCP_Sync_Env::block( "# END WordPress\n# BEGIN WordPress\na\n# END WordPress\n" ), 'An end before the beginning ends nothing.' );
		self::assertSame( 'a', AB_MCP_Sync_Env::block( "# BEGIN WordPress\na\n# END WordPress\n# BEGIN WordPress\nb\n# END WordPress\n" ), 'The first block.' );
	}

	public function testTheSchemaOfATableIsHashedWithoutItsPrefixAndAutoIncrement(): void {
		$a = "CREATE TABLE `wpa_log` (\n  `id` bigint(20) NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `wpa_log_fk` FOREIGN KEY (`id`) REFERENCES `wpa_posts` (`ID`)\n) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4";
		$b = "CREATE TABLE `wpb_log` (\n  `id` bigint(20) NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `wpb_log_fk` FOREIGN KEY (`id`) REFERENCES `wpb_posts` (`ID`)\n) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4";
		self::assertSame( AB_MCP_Sync_Env::schema( $a, 'wpa_' ), AB_MCP_Sync_Env::schema( $b, 'wpb_' ), 'The same schema under two prefixes and counters.' );
		self::assertNotSame( AB_MCP_Sync_Env::schema( $a, 'wpa_' ), AB_MCP_Sync_Env::schema( str_replace( 'bigint(20)', 'int(11)', $b ), 'wpb_' ), 'Another column type is another schema.' );
		self::assertNotSame( AB_MCP_Sync_Env::schema( $a, 'wpa_' ), AB_MCP_Sync_Env::schema( $a, 'wpb_' ), 'Only the prefix of the site is taken out.' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}\z/', AB_MCP_Sync_Env::schema( $a, 'wpa_' ) );
	}

	public function testTheValueOfAConstantIsShownPlainOrByItsHashOnly(): void {
		self::assertTrue( AB_MCP_Sync_Env::constant_value( true ) );
		self::assertSame( 0, AB_MCP_Sync_Env::constant_value( 0 ) );
		self::assertNull( AB_MCP_Sync_Env::constant_value( null ) );
		self::assertSame( '256M', AB_MCP_Sync_Env::constant_value( '256M' ) );
		self::assertSame( 'utf8mb4_unicode_ci', AB_MCP_Sync_Env::constant_value( 'utf8mb4_unicode_ci' ) );
		self::assertSame( '', AB_MCP_Sync_Env::constant_value( '' ) );
		self::assertSame( '1.5', AB_MCP_Sync_Env::constant_value( 1.5 ), 'A float as its text: no float in a line.' );
		self::assertSame( 'sha256:' . hash( 'sha256', '/var/log/wp/debug.log' ), AB_MCP_Sync_Env::constant_value( '/var/log/wp/debug.log' ), 'A path by its SHA-256 only.' );
		self::assertSame( 'sha256:' . hash( 'sha256', str_repeat( 'a', 65 ) ), AB_MCP_Sync_Env::constant_value( str_repeat( 'a', 65 ) ), 'A text longer than 64 characters by its SHA-256 only.' );
		self::assertStringStartsWith( 'sha256:', (string) AB_MCP_Sync_Env::constant_value( array( 'a' => 1 ) ), 'Any other value by its SHA-256 only.' );
	}

	public function testNoConstantThatHoldsASecretIsShown(): void {
		self::assertSame( array(), array_intersect( AB_MCP_Sync_Env::CONSTANTS, AB_MCP_Sync_Env::NEVER ), 'None of the secrets in the list.' );
		foreach ( AB_MCP_Sync_Env::CONSTANTS as $name ) {
			self::assertTrue( AB_MCP_Sync_Env::shown( $name, array() ), $name . ' is no name of a credential.' );
			self::assertFalse( AB_MCP_Redactor::redacts_key( $name ), $name );
		}
		foreach ( AB_MCP_Sync_Env::NEVER as $name ) {
			self::assertFalse( AB_MCP_Sync_Env::shown( $name, array() ), $name . ' is never shown, also where a list would name it.' );
			self::assertFalse( AB_MCP_Sync_Env::shown( strtolower( $name ), array() ), $name . ' in any case.' );
		}
		self::assertFalse( AB_MCP_Sync_Env::shown( 'MY_API_KEY', array() ), 'A name the redactor redacts.' );
		self::assertFalse( AB_MCP_Sync_Env::shown( 'WP_DEBUG', array( 'wp_debug' ) ), 'A name of the rules of this site.' );
		self::assertContains( 'DB_CHARSET', AB_MCP_Sync_Env::CONSTANTS );
		foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $secret ) {
			self::assertContains( $secret, AB_MCP_Sync_Env::NEVER, $secret );
		}
	}

	public function testTheConstantsShownLeaveOutWhatTheRulesOfThisSiteRedact(): void {
		$all = AB_MCP_Sync_Env::constants();
		self::assertSame( AB_MCP_Sync_Env::CONSTANTS, array_keys( $all ), 'Every constant of the list, in its order.' );
		foreach ( $all as $name => $shown ) {
			self::assertSame( array( 'defined', 'value' ), array_keys( $shown ), $name );
			self::assertSame( defined( $name ), $shown['defined'], $name );
			if ( ! $shown['defined'] ) {
				self::assertNull( $shown['value'], $name . ': no value where it is not defined.' );
			}
		}
		add_filter( 'ab_mcp_redact_keys', static fn( array $keys ): array => array_merge( $keys, array( 'WP_MEMORY_LIMIT' ) ) );
		$less = AB_MCP_Sync_Env::constants();
		self::assertArrayNotHasKey( 'WP_MEMORY_LIMIT', $less, 'ab_mcp_redact_keys: never read.' );
		self::assertCount( count( $all ) - 1, $less );
	}

	/* ---------------------------------------------------------- lines */

	/** A line of each kind, as read() writes it. */
	private static function lines(): array {
		return array(
			'page'      => array( 'env' => 'page', 'last' => true, 'ref' => 'page', 'seq' => 1, 'walk' => str_repeat( 'a1', 16 ) ),
			'core'      => array( 'env' => 'core', 'ref' => 'wordpress', 'value' => '7.1.3' ),
			'plugin'    => array( 'active' => true, 'env' => 'plugin', 'ref' => 'akismet/akismet.php', 'version' => '5.3' ),
			'theme'     => array( 'active' => null, 'env' => 'theme', 'ref' => 'twentytwentyfive', 'version' => null ),
			'mu_plugin' => array( 'bytes' => 12, 'env' => 'mu_plugin', 'ref' => 'loader.php', 'sha256' => str_repeat( 'b', 64 ) ),
			'dropin'    => array( 'bytes' => null, 'env' => 'dropin', 'ref' => 'object-cache.php', 'sha256' => null ),
			'host'      => array( 'defined' => true, 'env' => 'host', 'ref' => 'php', 'value' => '8.3.1' ),
			'constant'  => array( 'defined' => false, 'env' => 'host', 'ref' => 'constant:WP_DEBUG', 'value' => null ),
			'table'     => array( 'env' => 'table', 'ref' => 'gf_form', 'rows' => 3, 'schema' => str_repeat( 'c', 64 ) ),
			'tables'    => array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' ),
			'files'     => array( 'attached' => 2, 'bytes' => 10, 'complete' => true, 'env' => 'files', 'files' => 1, 'ref' => 'uploads', 'skipped' => 0, 'why' => null ),
			'unfinished' => array( 'attached' => 0, 'bytes' => 0, 'complete' => false, 'env' => 'files', 'files' => 0, 'ref' => 'fonts', 'skipped' => 0, 'why' => 'by_code' ),
		);
	}

	public function testALineOfEachKindHasItsShape(): void {
		foreach ( self::lines() as $name => $line ) {
			self::assertTrue( AB_MCP_Sync_Env::shaped( $line ), $name );
		}
		self::assertSame( array_keys( AB_MCP_Sync_Env::LINES ), array_values( array_unique( array_column( self::lines(), 'env' ) ) ), 'A line of every kind.' );
	}

	public function testALineWithAFieldMissingTooManyOrOfAnotherTypeIsNoLine(): void {
		foreach ( self::lines() as $name => $line ) {
			foreach ( array_keys( $line ) as $field ) {
				$less = $line;
				unset( $less[ $field ] );
				self::assertFalse( AB_MCP_Sync_Env::shaped( $less ), "$name without $field" );
			}
			self::assertFalse( AB_MCP_Sync_Env::shaped( $line + array( 'value_raw' => 'x' ) ), "$name with a field it has no place for" );
			self::assertFalse( AB_MCP_Sync_Env::shaped( $line + array( 'sha256' => str_repeat( 'd', 64 ), 'rows' => 1 ) ), "$name with a field of another kind" );
		}
		$wrong = array(
			array( 'page', 'seq', 0 ),
			array( 'page', 'seq', '1' ),
			array( 'page', 'walk', str_repeat( 'A1', 16 ) ),
			array( 'page', 'last', 1 ),
			array( 'page', 'ref', 'other' ),
			array( 'core', 'ref', 'php' ),
			array( 'core', 'value', 7 ),
			array( 'plugin', 'active', 'yes' ),
			array( 'plugin', 'version', array( '1' ) ),
			array( 'plugin', 'ref', '' ),
			array( 'plugin', 'ref', "bad\x01name" ),
			array( 'plugin', 'ref', "\xff" ),
			array( 'mu_plugin', 'sha256', str_repeat( 'B', 64 ) ),
			array( 'mu_plugin', 'bytes', -1 ),
			array( 'dropin', 'ref', 'my-dropin.php' ),
			array( 'host', 'ref', 'secret' ),
			array( 'host', 'defined', false ),
			array( 'host', 'value', 1.5 ),
			array( 'constant', 'ref', 'constant:DB_PASSWORD' ),
			array( 'constant', 'value', 'x' ),
			array( 'table', 'rows', 1.0 ),
			array( 'table', 'schema', 'abc' ),
			array( 'tables', 'ref', 'all' ),
			array( 'tables', 'count', null ),
			array( 'files', 'ref', 'plugins' ),
			array( 'files', 'why', 'by_code' ),
			array( 'unfinished', 'why', null ),
			array( 'files', 'files', null ),
		);
		$lines = self::lines();
		foreach ( $wrong as list( $name, $field, $value ) ) {
			$line           = $lines[ $name ];
			$line[ $field ] = $value;
			self::assertFalse( AB_MCP_Sync_Env::shaped( $line ), "$name: $field " . var_export( $value, true ) );
		}
		self::assertFalse( AB_MCP_Sync_Env::shaped( array( 'env' => 'secret', 'ref' => 'x' ) ), 'A kind of line it does not know.' );
		self::assertFalse( AB_MCP_Sync_Env::shaped( array( 'env' => 'core', 'ref' => array( 'wordpress' ), 'value' => '7' ) ), 'A ref that is no text.' );
	}

	public function testANameThatIsNoPlainTextIsHeldByItsBytes(): void {
		self::assertSame( 'akismet/akismet.php', AB_MCP_Sync_Env::name( 'akismet/akismet.php' ) );
		self::assertSame( 'bytes:ff41', AB_MCP_Sync_Env::name( "\xffA" ), 'Not UTF-8.' );
		self::assertSame( 'bytes:610a', AB_MCP_Sync_Env::name( "a\n" ), 'A control character.' );
		self::assertSame( 'bytes:' . bin2hex( str_repeat( 'a', 512 ) ), AB_MCP_Sync_Env::name( str_repeat( 'a', 1025 ) ), 'Longer than a line holds.' );
		self::assertTrue( AB_MCP_Sync_Env::shaped( array( 'active' => false, 'env' => 'plugin', 'ref' => AB_MCP_Sync_Env::name( "\xffA" ), 'version' => '1' ) ) );
	}

	public function testAStateIsOnlyOneReadWrites(): void {
		$state = array(
			'after'    => 'gf_form',
			'attached' => 0,
			'bytes'    => 0,
			'files'    => 0,
			'seq'      => 2,
			'skipped'  => 0,
			'step'     => 1,
			'walk'     => str_repeat( 'ab', 16 ),
		);
		self::assertTrue( AB_MCP_Sync_Env::state_shaped( $state ) );
		$wrong = array(
			array( 'seq', 0 ),
			array( 'step', count( AB_MCP_Sync_Env::STEPS ) ),
			array( 'step', -1 ),
			array( 'walk', 'x' ),
			array( 'after', '' ),
			array( 'after', 3 ),
			array( 'files', -1 ),
			array( 'bytes', '1' ),
		);
		foreach ( $wrong as list( $field, $value ) ) {
			$bad           = $state;
			$bad[ $field ] = $value;
			self::assertFalse( AB_MCP_Sync_Env::state_shaped( $bad ), $field . ' ' . var_export( $value, true ) );
		}
		$more          = $state;
		$more['extra'] = 1;
		self::assertFalse( AB_MCP_Sync_Env::state_shaped( $more ) );
		self::assertFalse( AB_MCP_Sync_Env::state_shaped( 'state' ) );
		$read = AB_MCP_Sync_Env::read( array( 'step' => 1 ), 60 );
		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'ab_mcp_sync_env_state', $read->get_error_code() );
	}

	/* --------------------------------------------------------- compare */

	/** One entry of a comparison of one line on each side (null: none), the lists of tables whole on both. */
	private static function one( ?array $s, ?array $l, array $more_s = array(), array $more_l = array() ): array {
		$whole   = array( array( 'complete' => true, 'count' => 0, 'env' => 'tables', 'ref' => 'tables' ) );
		$staging = array_merge( null === $s ? array() : array( $s ), $whole, $more_s );
		$live    = array_merge( null === $l ? array() : array( $l ), $whole, $more_l );
		$out     = AB_MCP_Sync_Env::compare( $staging, $live );
		self::assertCount( 1, $out['entries'], json_encode( $out['entries'] ) );
		return $out['entries'][0];
	}

	private static function plugin( string $version, ?bool $active = true ): array {
		return array( 'active' => $active, 'env' => 'plugin', 'ref' => 'shop/shop.php', 'version' => $version );
	}

	private static function lock_of( array $entry ): array {
		return array( $entry['locks'][0]['key'], $entry['locks'][0]['stage'] );
	}

	public function testAPluginOfTheSameVersionIsCountedNeverEqual(): void {
		$e = self::one( self::plugin( '2.0' ), self::plugin( '2.0' ) );
		self::assertSame( array( 'environment', 'plugin', 'shop/shop.php', 'counted', 'locked' ), array( $e['kind'], $e['subtype'], $e['ref'], $e['result'], $e['action'] ) );
		self::assertSame( array( 'not compared', 'Z8b' ), array( $e['contents'], $e['stage'] ) );
		self::assertSame( array( 'env:plugin', 'Z8b' ), self::lock_of( $e ) );
		self::assertStringContainsString( 'Counted, contents not compared', $e['words'] );
		self::assertStringContainsString( 'from Z8b', $e['words'] );
		self::assertSame( array( 'active' => true, 'version' => '2.0' ), $e['staging'] );
	}

	public function testAPluginOfAnotherVersionOrStateDiffers(): void {
		$e = self::one( self::plugin( '2.0' ), self::plugin( '1.9' ) );
		self::assertSame( array( 'differs', array( 'version' ), 'locked' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertArrayNotHasKey( 'contents', $e );
		$e = self::one( self::plugin( '2.0', true ), self::plugin( '2.0', false ) );
		self::assertSame( array( 'differs', array( 'active' ) ), array( $e['result'], $e['differs'] ) );
		$e = self::one( self::plugin( '2.0', true ), self::plugin( '1.0', false ) );
		self::assertSame( array( 'version', 'active' ), $e['differs'] );
	}

	public function testAPluginOnOneSideOnlyIsLocked(): void {
		$e = self::one( self::plugin( '2.0' ), null );
		self::assertSame( array( 'only_staging', 'locked', null ), array( $e['result'], $e['action'], $e['live'] ) );
		self::assertSame( array( 'env:plugin', 'Z8b' ), self::lock_of( $e ) );
		$e = self::one( null, self::plugin( '2.0' ) );
		self::assertSame( array( 'only_live', 'locked', null ), array( $e['result'], $e['action'], $e['staging'] ) );
	}

	public function testAValueThatCouldNotBeReadIsNotCompared(): void {
		$e = self::one( self::plugin( '2.0', null ), self::plugin( '2.0' ) );
		self::assertSame( 'not_compared', $e['result'] );
		self::assertStringContainsString( 'the staging site', $e['why'] );
		$e = self::one( self::plugin( '2.0' ), array( 'version' => null ) + self::plugin( '2.0' ) );
		self::assertSame( 'not_compared', $e['result'] );
		self::assertStringContainsString( 'this site', $e['why'] );
		$mu = array( 'bytes' => null, 'env' => 'mu_plugin', 'ref' => 'x.php', 'sha256' => null );
		self::assertSame( 'not_compared', self::one( $mu, array( 'bytes' => 1, 'sha256' => str_repeat( 'a', 64 ) ) + $mu )['result'], 'A file that could not be read is not equal to one.' );
		$core = array( 'env' => 'core', 'ref' => 'wordpress', 'value' => null );
		self::assertSame( 'not_compared', self::one( $core, $core )['result'], 'Two unread versions are not the same version.' );
	}

	public function testAThemeIsCountedOrDiffersAsAPlugin(): void {
		$theme = array( 'active' => true, 'env' => 'theme', 'ref' => 'twentytwentyfive', 'version' => '1.2' );
		$e     = self::one( $theme, $theme );
		self::assertSame( array( 'counted', 'locked' ), array( $e['result'], $e['action'] ) );
		self::assertSame( array( 'env:theme', 'Z8b' ), self::lock_of( $e ) );
		$e = self::one( $theme, array( 'active' => false ) + $theme );
		self::assertSame( array( 'differs', array( 'active' ) ), array( $e['result'], $e['differs'] ) );
	}

	public function testWordPressOfTheSameVersionIsCountedAndTheLanguageShownOnly(): void {
		$wp = array( 'env' => 'core', 'ref' => 'wordpress', 'value' => '7.1.3' );
		$e  = self::one( $wp, $wp );
		self::assertSame( array( 'counted', 'locked' ), array( $e['result'], $e['action'] ) );
		self::assertSame( array( 'env:wordpress', 'Z8b' ), self::lock_of( $e ) );
		self::assertStringContainsString( 'wp_update_core', $e['locks'][0]['reason'] );
		$e = self::one( $wp, array( 'value' => '7.2' ) + $wp );
		self::assertSame( array( 'differs', array( 'value' ) ), array( $e['result'], $e['differs'] ) );
		$locale = array( 'env' => 'core', 'ref' => 'locale', 'value' => 'de_CH' );
		$e      = self::one( $locale, $locale );
		self::assertSame( array( 'equal', 'none' ), array( $e['result'], $e['action'] ) );
		$e = self::one( $locale, array( 'value' => 'en_US' ) + $locale );
		self::assertSame( array( 'differs', 'none' ), array( $e['result'], $e['action'] ), 'The option WPLANG is compared with the options: here shown only.' );
		self::assertStringContainsString( 'Shown only', $e['words'] );
		self::assertArrayNotHasKey( 'locks', $e );
	}

	public function testAMustUsePluginIsEqualOrDiffersByItsHash(): void {
		$mu = array( 'bytes' => 10, 'env' => 'mu_plugin', 'ref' => 'site.php', 'sha256' => str_repeat( 'a', 64 ) );
		$e  = self::one( $mu, $mu );
		self::assertSame( array( 'equal', 'none' ), array( $e['result'], $e['action'] ) );
		self::assertArrayNotHasKey( 'locks', $e );
		$e = self::one( $mu, array( 'sha256' => str_repeat( 'b', 64 ) ) + $mu );
		self::assertSame( array( 'differs', array( 'contents' ), 'locked' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertSame( array( 'env:mu_plugin', 'Z8b' ), self::lock_of( $e ) );
		self::assertSame( array( 'bytes' => 10 ), $e['staging'], 'No hash in an entry.' );
		$e = self::one( $mu, null );
		self::assertSame( array( 'only_staging', 'locked' ), array( $e['result'], $e['action'] ) );
	}

	public function testADropInIsEqualOrDiffersAndIsChangedAtTheHost(): void {
		$d = array( 'bytes' => 10, 'env' => 'dropin', 'ref' => 'object-cache.php', 'sha256' => str_repeat( 'a', 64 ) );
		$e = self::one( $d, $d );
		self::assertSame( array( 'equal', 'none' ), array( $e['result'], $e['action'] ) );
		$e = self::one( $d, array( 'sha256' => str_repeat( 'b', 64 ) ) + $d );
		self::assertSame( array( 'differs', array( 'contents' ), 'host' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertStringContainsString( 'Change at the host', $e['words'] );
		self::assertArrayNotHasKey( 'locks', $e );
		$e = self::one( null, $d );
		self::assertSame( array( 'only_live', 'host' ), array( $e['result'], $e['action'] ) );
	}

	public function testTheValuesOfTheHostAreEqualOrDifferAndChangedAtTheHost(): void {
		$php = array( 'defined' => true, 'env' => 'host', 'ref' => 'php', 'value' => '8.3.1' );
		self::assertSame( array( 'equal', 'none' ), array_values( array_intersect_key( self::one( $php, $php ), array_flip( array( 'result', 'action' ) ) ) ) );
		$e = self::one( $php, array( 'value' => '8.2.0' ) + $php );
		self::assertSame( array( 'differs', array( 'value' ), 'host' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertSame( '8.3.1', $e['staging']['value'], 'A value of the host is shown.' );
		$debug = array( 'defined' => true, 'env' => 'host', 'ref' => 'constant:WP_DEBUG', 'value' => true );
		$none  = array( 'defined' => false, 'value' => null ) + $debug;
		$e     = self::one( $debug, $none );
		self::assertSame( array( 'differs', array( 'defined' ), 'host' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertSame( 'equal', self::one( $none, $none )['result'], 'Not defined on both sides.' );
		$e = self::one( array( 'value' => false ) + $debug, $debug );
		self::assertSame( array( 'differs', array( 'value' ) ), array( $e['result'], $e['differs'] ) );
		$cache = array( 'defined' => true, 'env' => 'host', 'ref' => 'database', 'value' => null );
		$e     = self::one( $cache, array( 'value' => '11.4.2-MariaDB' ) + $cache );
		self::assertSame( array( 'not_compared', 'host' ), array( $e['result'], $e['action'] ) );
	}

	public function testAValueHeldByItsHashIsNotShown(): void {
		$h = array( 'defined' => true, 'env' => 'host', 'ref' => 'htaccess', 'value' => str_repeat( 'a', 64 ) );
		$e = self::one( $h, array( 'value' => 'absent' ) + $h );
		self::assertSame( array( 'differs', 'present', 'absent' ), array( $e['result'], $e['staging']['value'], $e['live']['value'] ) );
		$log = array( 'defined' => true, 'env' => 'host', 'ref' => 'constant:WP_DEBUG_LOG', 'value' => 'sha256:' . str_repeat( 'b', 64 ) );
		$e   = self::one( $log, $log );
		self::assertSame( array( 'equal', 'not shown' ), array( $e['result'], $e['staging']['value'] ) );
		self::assertStringNotContainsString( str_repeat( 'b', 64 ), (string) json_encode( $e ) );
	}

	public function testATableWithTheSameRowsAndSchemaIsCountedNeverEqual(): void {
		$t    = array( 'env' => 'table', 'ref' => 'gf_form', 'rows' => 3, 'schema' => str_repeat( 'c', 64 ) );
		$list = array( array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' ) );
		$e    = self::one( $t, $t, $list, $list );
		self::assertSame( array( 'counted', 'locked', 'not compared', 'Z8d' ), array( $e['result'], $e['action'], $e['contents'], $e['stage'] ) );
		self::assertSame( array( 'env:table', 'Z8d' ), self::lock_of( $e ) );
		self::assertSame( array( 'rows' => 3 ), $e['live'], 'Its rows, not the hash of its schema.' );
		$e = self::one( $t, array( 'rows' => 4 ) + $t, $list, $list );
		self::assertSame( array( 'differs', array( 'rows' ) ), array( $e['result'], $e['differs'] ) );
		$e = self::one( $t, array( 'schema' => str_repeat( 'd', 64 ) ) + $t, $list, $list );
		self::assertSame( array( 'differs', array( 'schema' ) ), array( $e['result'], $e['differs'] ) );
		$e = self::one( null, $t, $list, $list );
		self::assertSame( array( 'only_live', 'locked' ), array( $e['result'], $e['action'] ) );
	}

	public function testTablesOfASideWhoseListWasNotReadWholeAreNotCompared(): void {
		$t       = array( 'env' => 'table', 'ref' => 'gf_form', 'rows' => 3, 'schema' => str_repeat( 'c', 64 ) );
		$whole   = array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' );
		$unread  = array( 'complete' => false, 'count' => 0 ) + $whole;
		$out = AB_MCP_Sync_Env::compare( array( $unread, $t ), array( $whole, $t ) );
		self::assertSame( array( 'not_compared', 'not_compared' ), array_column( $out['entries'], 'result' ), 'A list of the staging site not read whole.' );
		self::assertStringContainsString( 'the staging site', $out['entries'][0]['why'] );
		$out     = AB_MCP_Sync_Env::compare( array( $whole, $t ), array( $unread ) );
		$results = array_column( $out['entries'], 'result', 'ref' );
		self::assertSame( array( '*' => 'not_compared', 'gf_form' => 'not_compared' ), $results, 'Never only on staging where the list here was not read.' );
		self::assertStringContainsString( 'this site', $out['entries'][0]['why'] );
		$out = AB_MCP_Sync_Env::compare( array(), array( $whole ) );
		self::assertSame( array( 'not_compared' ), array_column( $out['entries'], 'result' ), 'Without a list of the staging site, also where neither names a table.' );
		self::assertStringContainsString( 'the staging site', $out['entries'][0]['why'] );
		self::assertSame( array(), AB_MCP_Sync_Env::compare( array( $whole ), array( $whole ) )['entries'], 'Both lists whole and no table: nothing to say.' );
	}

	public function testARootOfTheSameCountIsCountedNeverEqual(): void {
		$f = array( 'attached' => 0, 'bytes' => 10, 'complete' => true, 'env' => 'files', 'files' => 2, 'ref' => 'languages', 'skipped' => 0, 'why' => null );
		$e = self::one( $f, $f );
		self::assertSame( array( 'counted', 'locked', 'Z8b' ), array( $e['result'], $e['action'], $e['stage'] ) );
		self::assertSame( array( 'env:languages', 'Z8b' ), self::lock_of( $e ) );
		self::assertArrayNotHasKey( 'why', $e['staging'] );
		$e = self::one( $f, array( 'files' => 3 ) + $f );
		self::assertSame( array( 'differs', array( 'files' ) ), array( $e['result'], $e['differs'] ) );
		$e = self::one( $f, array( 'bytes' => 11 ) + $f );
		self::assertSame( array( 'differs', array( 'bytes' ) ), array( $e['result'], $e['differs'] ) );
		$e = self::one( $f, array( 'skipped' => 5, 'attached' => 9 ) + $f );
		self::assertSame( 'counted', $e['result'], 'What was skipped or attached is shown, not compared.' );
	}

	public function testARootNotCountedToItsEndIsNotComparedWithWhy(): void {
		$f = array( 'attached' => 0, 'bytes' => 10, 'complete' => true, 'env' => 'files', 'files' => 2, 'ref' => 'uploads', 'skipped' => 0, 'why' => null );
		$e = self::one( $f, array( 'complete' => false, 'why' => 'by_code' ) + $f );
		self::assertSame( array( 'not_compared', 'locked' ), array( $e['result'], $e['action'] ) );
		self::assertStringContainsString( 'this site', $e['why'] );
		self::assertStringContainsString( 'code decides', $e['why'] );
		self::assertSame( 'by_code', $e['live']['why'] );
		$e = self::one( array( 'complete' => false, 'why' => 'attachments_too_many' ) + $f, $f );
		self::assertStringContainsString( 'the staging site', $e['why'] );
		self::assertStringContainsString( 'too many', $e['why'] );
	}

	public function testEachLockHasItsKeyArtStageAndReason(): void {
		$cases = array(
			'plugin'           => self::plugin( '1' ),
			'theme'            => array( 'active' => true, 'env' => 'theme', 'ref' => 't', 'version' => '1' ),
			'core'             => array( 'env' => 'core', 'ref' => 'wordpress', 'value' => '7.1.3' ),
			'mu_plugin'        => array( 'bytes' => 1, 'env' => 'mu_plugin', 'ref' => 'a.php', 'sha256' => str_repeat( 'a', 64 ) ),
			'table'            => array( 'env' => 'table', 'ref' => 'x', 'rows' => 1, 'schema' => str_repeat( 'a', 64 ) ),
			'files:languages'  => array( 'attached' => 0, 'bytes' => 1, 'complete' => true, 'env' => 'files', 'files' => 1, 'ref' => 'languages', 'skipped' => 0, 'why' => null ),
			'files:fonts'      => array( 'attached' => 0, 'bytes' => 1, 'complete' => true, 'env' => 'files', 'files' => 1, 'ref' => 'fonts', 'skipped' => 0, 'why' => null ),
			'files:mu-plugins' => array( 'attached' => 0, 'bytes' => 1, 'complete' => true, 'env' => 'files', 'files' => 1, 'ref' => 'mu-plugins', 'skipped' => 0, 'why' => null ),
			'files:uploads'    => array( 'attached' => 0, 'bytes' => 1, 'complete' => true, 'env' => 'files', 'files' => 1, 'ref' => 'uploads', 'skipped' => 0, 'why' => null ),
		);
		self::assertSame( array_keys( AB_MCP_Sync_Env::LOCKS ), array_keys( $cases ), 'A case per lock.' );
		$tables = array( array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' ) );
		$want   = array(
			'plugin'           => array( 'env:plugin', 'A11', 'Z8b' ),
			'theme'            => array( 'env:theme', 'A11', 'Z8b' ),
			'core'             => array( 'env:wordpress', 'A17', 'Z8b' ),
			'mu_plugin'        => array( 'env:mu_plugin', 'A21', 'Z8b' ),
			'table'            => array( 'env:table', 'A16', 'Z8d' ),
			'files:languages'  => array( 'env:languages', 'A21', 'Z8b' ),
			'files:fonts'      => array( 'env:fonts', 'A20', 'Z8b' ),
			'files:mu-plugins' => array( 'env:mu_files', 'A21', 'Z8b' ),
			'files:uploads'    => array( 'env:uploads', 'A22', 'Z8b' ),
		);
		foreach ( $cases as $key => $line ) {
			$lock = AB_MCP_Sync_Env::LOCKS[ $key ];
			self::assertSame( $want[ $key ], array( $lock['key'], $lock['art'], $lock['stage'] ), $key );
			self::assertNotSame( '', $lock['reason'] );
			$out = AB_MCP_Sync_Env::compare( array( $line, $tables[0] ), $tables );
			self::assertSame( array( 'only_staging', 'locked' ), array( $out['entries'][0]['result'], $out['entries'][0]['action'] ), $key );
			self::assertSame( array( array( 'key' => $lock['key'], 'stage' => $lock['stage'], 'reason' => $lock['reason'] ) ), $out['entries'][0]['locks'], $key );
			self::assertSame( array( array( 'key' => $lock['key'], 'stage' => $lock['stage'], 'reason' => $lock['reason'], 'entries' => 1 ) ), $out['blocked'], $key . ': blocked, with how many entries.' );
		}
	}

	public function testNothingCountedIsEverEqualAndNothingEqualIsLocked(): void {
		$tables = array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' );
		$lines  = array_values( array_filter( self::lines(), static fn( $l ) => 'page' !== $l['env'] && 'tables' !== $l['env'] && ( 'files' !== $l['env'] || $l['complete'] ) ) );
		$out    = AB_MCP_Sync_Env::compare( array_merge( $lines, array( $tables ) ), array_merge( $lines, array( $tables ) ) );
		foreach ( $out['entries'] as $entry ) {
			if ( in_array( $entry['subtype'], array( 'plugin', 'theme', 'table', 'files' ), true ) || 'wordpress' === $entry['ref'] ) {
				self::assertNotSame( 'equal', $entry['result'], $entry['subtype'] . ' ' . $entry['ref'] );
			}
			if ( 'equal' === $entry['result'] ) {
				self::assertSame( 'none', $entry['action'], $entry['ref'] );
				self::assertArrayNotHasKey( 'locks', $entry );
			}
		}
		self::assertStringNotContainsString( '"sha256"', (string) json_encode( $out ), 'No hash in an entry.' );
		self::assertStringNotContainsString( '"schema"', (string) json_encode( $out ) );
		self::assertStringNotContainsString( str_repeat( 'b', 64 ), (string) json_encode( $out ) );
	}

	public function testTheEntriesComeInTheOrderOfTheirKindThenByRef(): void {
		$tables = array( 'complete' => true, 'count' => 2, 'env' => 'tables', 'ref' => 'tables' );
		$lines  = array(
			$tables,
			array( 'env' => 'table', 'ref' => 'b', 'rows' => 1, 'schema' => str_repeat( 'a', 64 ) ),
			array( 'env' => 'table', 'ref' => 'A', 'rows' => 1, 'schema' => str_repeat( 'a', 64 ) ),
			self::plugin( '1' ),
			array( 'env' => 'core', 'ref' => 'wordpress', 'value' => '7.1.3' ),
			array( 'env' => 'page', 'last' => true, 'ref' => 'page', 'seq' => 1, 'walk' => str_repeat( 'a', 32 ) ),
		);
		$out = AB_MCP_Sync_Env::compare( $lines, array( $tables ) );
		self::assertSame( array( 'core:wordpress', 'plugin:shop/shop.php', 'table:A', 'table:b' ), array_map( static fn( $e ) => $e['subtype'] . ':' . $e['ref'], $out['entries'] ), 'No page, no list of tables.' );
		self::assertSame( array( 'entries' => 4, 'results' => array( 'only_staging' => 4 ), 'actions' => array( 'locked' => 4 ) ), $out['counts'] );
	}

	public function testTheLoaderAndTheDropInsAreThoseOfWordPressAndThisPlugin(): void {
		self::assertSame( \AB_MCP_Preview_Protection::LOADER_FILE, AB_MCP_Sync_Env::LOADER );
		self::assertSame( array( 'advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'object-cache.php', 'php-error.php', 'fatal-error-handler.php' ), AB_MCP_Sync_Env::DROPINS, 'The integration suite holds them against _get_dropins().' );
		$this->file( 'site.php', '<?php' );
		$this->file( 'notes.txt', 'x' );
		$this->file( AB_MCP_Sync_Env::LOADER, '<?php' );
		$this->file( 'sub/inner.php', '<?php' );
		$this->file( 'b.php', '<?php' );
		mkdir( $this->dir . '/folder.php' );
		self::assertSame( array( 'b.php', 'site.php' ), AB_MCP_Sync_Env::mu_files( $this->dir ), 'The PHP files WordPress loads, without the loader and a folder named so, by strcmp().' );
		self::assertSame( array(), AB_MCP_Sync_Env::mu_files( $this->dir . '/none' ) );
		self::assertSame( array( 'bytes' => 5, 'sha256' => hash( 'sha256', '<?php' ) ), AB_MCP_Sync_Env::file_hash( $this->dir . '/site.php' ) );
		self::assertSame( array( 'bytes' => null, 'sha256' => null ), AB_MCP_Sync_Env::file_hash( $this->dir . '/sub' ), 'A folder is no file.' );
	}

	public function testTheHeadOfAFileIsReadAsGetFileDataReadsIt(): void {
		$this->file( 'p.php', "<?php\n/**\n * Plugin Name: Shop */\n * Version:   2.1.0 \r * Author: x\n */" );
		self::assertSame( array( 'Plugin Name' => 'Shop', 'Version' => '2.1.0', 'Text Domain' => '' ), AB_MCP_Sync_Env::head( $this->dir . '/p.php', array( 'Plugin Name', 'Version', 'Text Domain' ) ), 'Without the end of the comment, a carriage return as a line end, a header it does not name empty.' );
		$this->file( 'q.php', '<?php // Version: 3 ?>' . str_repeat( 'x', 9000 ) . "\n * Plugin Name: Late" );
		self::assertSame( array( 'Version' => '3', 'Plugin Name' => '' ), AB_MCP_Sync_Env::head( $this->dir . '/q.php', array( 'Version', 'Plugin Name' ) ), 'Without the end of PHP; the first 8 KiB only.' );
		$this->file( 'r.php', " * version: 1\n * Version: 2" );
		self::assertSame( '1', AB_MCP_Sync_Env::head( $this->dir . '/r.php', array( 'Version' ) )['Version'], 'The first line that names it, in any case.' );
		self::assertSame( array( 'Version' => '' ), AB_MCP_Sync_Env::head( $this->dir . '/none.php', array( 'Version' ) ) );
	}

	public function testThePluginsAreFoundAsGetPluginsFindsThem(): void {
		$this->file( 'hello.php', "<?php\n/* Plugin Name: Hello\n * Version: 1.7 */" );
		$this->file( 'readme.php', "<?php\n// No plugin.\n" );
		$this->file( 'notes.txt', 'Plugin Name: Not PHP' );
		$this->file( 'shop/shop.php', "<?php\n/* Plugin Name: Shop */" );
		$this->file( 'shop/inc/deep.php', "<?php\n/* Plugin Name: Too deep */" );
		$this->file( 'shop/.hidden.php', "<?php\n/* Plugin Name: Hidden */" );
		$this->file( '.git/x.php', "<?php\n/* Plugin Name: Hidden folder */" );
		$this->file( 'b/b.php', "<?php\n/* Plugin Name: B\n * Version: 0.1 */" );
		mkdir( $this->dir . '/folder.php' );
		self::assertSame( array( 'b/b.php' => '0.1', 'hello.php' => '1.7', 'shop/shop.php' => null ), AB_MCP_Sync_Env::plugin_files( $this->dir ), 'Its top level and one level down, with a name; a version null without one.' );
		self::assertSame( array(), AB_MCP_Sync_Env::plugin_files( $this->dir . '/none' ) );
		self::assertSame( array(), AB_MCP_Sync_Env::plugin_files( '' ) );
	}

	public function testTheThemesAreFoundAsSearchThemeDirectoriesFindsThem(): void {
		$this->file( 'one/twentyten/style.css', "/*\nTheme Name: Twenty Ten\nVersion: 4.5\n*/" );
		$this->file( 'one/group/child/style.css', "/*\nTheme Name: Child\n*/" );
		$this->file( 'one/group/child/deeper/style.css', "/* Theme Name: Too deep */" );
		$this->file( 'one/.hidden/style.css', '/* Theme Name: Hidden */' );
		$this->file( 'one/empty/index.php', '<?php' );
		$this->file( 'one/style.css', '/* Theme Name: Not a folder */' );
		$this->file( 'two/twentyten/style.css', "/* Theme Name: Twenty Ten\nVersion: 9 */" );
		$this->file( 'two/other/style.css', "/* Theme Name: Other\nVersion: 1 */" );
		self::assertSame( array( 'group/child' => null, 'twentyten' => '4.5', 'other' => '1' ), AB_MCP_Sync_Env::theme_dirs( array( $this->dir . '/one/', $this->dir . '/two', $this->dir . '/none' ) ), 'A folder with style.css, else one level down; the first root wins.' );
	}

	public function testWithoutAPackageOfTheEnvironmentItIsOneEntryNotComparedWithTheWay(): void {
		$out = AB_MCP_Sync_Env::without();
		self::assertCount( 1, $out['entries'] );
		$e = $out['entries'][0];
		self::assertSame( array( 'environment', 'environment', 'not_compared', 'none' ), array( $e['kind'], $e['subtype'], $e['result'], $e['action'] ) );
		self::assertStringContainsString( 'purpose=env', $e['why'] );
		self::assertStringContainsString( 'environment', $e['why'] );
		self::assertSame( array(), $out['blocked'] );
	}
}
