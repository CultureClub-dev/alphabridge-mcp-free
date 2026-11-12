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
 *   AUTO_INCREMENT and the prefix of the names of tables and constraints
 *   (never of a column, an index or a text), the value of a constant (a
 *   text that is not plain by its SHA-256 only), and no constant that holds
 *   a secret (NEVER, a name the redactor or ab_mcp_redact_keys redacts,
 *   also where the staging site shows it);
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
require_once __DIR__ . '/../includes/sync/class-sync-decision.php';
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

	public function testOnlyTheNamesOfTablesLoseThePrefixNeverAColumnOrAnIndex(): void {
		// A column of another name under another prefix: another schema
		// (GitHub-Codex, PR #136, P2: the prefix was taken out of every name).
		$staging = "CREATE TABLE `wp_events` (\n  `wp_id` bigint(20) NOT NULL,\n  KEY `wp_id` (`wp_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		$live    = "CREATE TABLE `live_events` (\n  `id` bigint(20) NOT NULL,\n  KEY `id` (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		self::assertNotSame( AB_MCP_Sync_Env::schema( $staging, 'wp_' ), AB_MCP_Sync_Env::schema( $live, 'live_' ), 'A column wp_id is no column id, whatever the prefix.' );
		$same = "CREATE TABLE `live_events` (\n  `wp_id` bigint(20) NOT NULL,\n  KEY `wp_id` (`wp_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		self::assertSame( AB_MCP_Sync_Env::schema( $staging, 'wp_' ), AB_MCP_Sync_Env::schema( $same, 'live_' ), 'The same column and index under two prefixes: the same schema.' );
		// A foreign key: the table it references loses the prefix, the
		// columns after it do not.
		$fk = static fn( string $p, string $col ): string => "CREATE TABLE `{$p}log` (\n  `wp_id` bigint(20) NOT NULL,\n  KEY `wp_id` (`wp_id`),\n  CONSTRAINT `{$p}log_ibfk_1` FOREIGN KEY (`wp_id`) REFERENCES `{$p}events` (`$col`) ON DELETE CASCADE\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		self::assertSame( AB_MCP_Sync_Env::schema( $fk( 'wp_', 'wp_id' ), 'wp_' ), AB_MCP_Sync_Env::schema( $fk( 'live_', 'wp_id' ), 'live_' ), 'A table of a plugin with a foreign key on another one: the same schema under two prefixes.' );
		self::assertNotSame( AB_MCP_Sync_Env::schema( $fk( 'wp_', 'wp_id' ), 'wp_' ), AB_MCP_Sync_Env::schema( $fk( 'live_', 'id' ), 'live_' ), 'The column a foreign key references keeps its name.' );
		// A text is no name: what a comment says stays as it is.
		$comment = static fn( string $p ): string => "CREATE TABLE `{$p}notes` (\n  `id` bigint(20) NOT NULL COMMENT 'REFERENCES `wp_events`'\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		self::assertSame( AB_MCP_Sync_Env::schema( $comment( 'wp_' ), 'wp_' ), AB_MCP_Sync_Env::schema( $comment( 'live_' ), 'live_' ), 'The same comment on both sides: the same schema.' );
		// As MariaDB writes a comment: a quote doubled, a backslash escaped.
		$escaped = static fn( string $p ): string => "CREATE TABLE `{$p}notes` (\n  `id` int(11) DEFAULT NULL COMMENT 'it''s a \\\\ back REFERENCES `wp_events`'\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		self::assertSame( AB_MCP_Sync_Env::schema( $escaped( 'wp_' ), 'wp_' ), AB_MCP_Sync_Env::schema( $escaped( 'live_' ), 'live_' ), 'A comment with a doubled quote and a backslash: the same schema.' );
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

	/**
	 * The line of a unit as read() writes it: a folder read whole (a plugin
	 * with its main file running), changed by $over.
	 */
	private static function unit( string $env, string $ref, array $over = array() ): array {
		$line = array(
			'active'   => null,
			'bytes'    => 10,
			'complete' => true,
			'env'      => $env,
			'files'    => 2,
			'form'     => 'folder',
			'hash'     => str_repeat( 'a', 64 ),
			'lock'     => null,
			'mains'    => null,
			'mtime'    => null,
			'own'      => false,
			'ref'      => $ref,
			'sha256'   => null,
			'version'  => null,
			'cause'    => null,
		);
		if ( 'plugin' === $env ) {
			$line['mains']   = array( 'main.php' );
			$line['active']  = array( 'main.php' );
			$line['version'] = '2.0';
		}
		$line = array_replace( $line, $over );
		ksort( $line );
		return $line;
	}

	/** The line of a unit of one file. */
	private static function file_unit( string $env, string $ref, string $sha = 'b', array $over = array() ): array {
		return self::unit( $env, $ref, array( 'form' => 'file', 'files' => 1, 'bytes' => 3, 'hash' => str_repeat( $sha, 64 ), 'sha256' => str_repeat( 'f', 64 ), 'mtime' => 1700000000 ) + $over );
	}

	/** A line of each kind, as read() writes it. */
	private static function lines(): array {
		return array(
			'page'         => array( 'env' => 'page', 'files' => 'ab-files/1', 'last' => true, 'ref' => 'page', 'seq' => 1, 'walk' => str_repeat( 'a1', 16 ) ),
			'core'         => array( 'env' => 'core', 'ref' => 'wordpress', 'value' => '7.1.3' ),
			'core_files'   => array( 'cause' => null, 'checked' => 3338, 'complete' => true, 'differs' => 0, 'env' => 'core_files', 'missing' => 0, 'ref' => 'files' ),
			'plugin'       => self::unit( 'plugin', 'akismet' ),
			'theme'        => self::unit( 'theme', 'twentytwentyfive', array( 'version' => '1.2' ) ),
			'mu_plugin'    => self::file_unit( 'mu_plugin', 'loader.php' ),
			'language'     => self::file_unit( 'language', 'plugins/akismet-de_CH.mo' ),
			'font'         => self::file_unit( 'font', 'inter/inter.woff2' ),
			'upload'       => self::file_unit( 'upload', '2026/11/list.pdf' ),
			'entries'      => array( 'entries' => array( array( 'a.php', 3, str_repeat( 'c', 64 ), 1700000000 ) ), 'env' => 'entries', 'ref' => 'plugin:akismet#1', 'seq' => 1, 'unit' => 'plugin:akismet' ),
			'theme_choice' => array( 'env' => 'theme_choice', 'ref' => 'theme_choice', 'stylesheet' => 'child', 'template' => 'parent' ),
			'dropin'       => array( 'bytes' => null, 'env' => 'dropin', 'ref' => 'object-cache.php', 'sha256' => null ),
			'host'         => array( 'defined' => true, 'env' => 'host', 'ref' => 'php', 'value' => '8.3.1' ),
			'constant'     => array( 'defined' => false, 'env' => 'host', 'ref' => 'constant:WP_DEBUG', 'value' => null ),
			'table'        => array( 'env' => 'table', 'ref' => 'gf_form', 'rows' => 3, 'schema' => str_repeat( 'c', 64 ) ),
			'tables'       => array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' ),
			'files'        => array( 'attached' => 2, 'bytes' => 10, 'complete' => true, 'env' => 'files', 'files' => 1, 'ref' => 'uploads', 'skipped' => 0, 'why' => null ),
			'unfinished'   => array( 'attached' => 0, 'bytes' => 0, 'complete' => false, 'env' => 'files', 'files' => 0, 'ref' => 'uploads', 'skipped' => 0, 'why' => 'by_code' ),
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
			array( 'page', 'files', 'ab-files/2' ),
			array( 'core_files', 'ref', 'core' ),
			array( 'core_files', 'cause', 'unreachable' ),
			array( 'core_files', 'cause', 'secret' ),
			array( 'core_files', 'differs', -1 ),
			array( 'plugin', 'active', 'yes' ),
			array( 'plugin', 'active', array( '' ) ),
			array( 'plugin', 'active', array( 'a' => 'main.php' ) ),
			array( 'plugin', 'mains', array( "\xff" ) ),
			array( 'plugin', 'version', array( '1' ) ),
			array( 'plugin', 'ref', '' ),
			array( 'plugin', 'ref', "bad\x01name" ),
			array( 'plugin', 'ref', "\xff" ),
			array( 'plugin', 'ref', '*' ),
			array( 'plugin', 'form', 'tree' ),
			array( 'plugin', 'cause', 'secret' ),
			array( 'plugin', 'lock', 'code:other' ),
			array( 'plugin', 'complete', false ),
			array( 'plugin', 'lock', 'code:link' ),
			array( 'plugin', 'sha256', str_repeat( 'f', 64 ) ),
			array( 'plugin', 'own', null ),
			array( 'mu_plugin', 'sha256', str_repeat( 'B', 64 ) ),
			array( 'mu_plugin', 'bytes', -1 ),
			array( 'mu_plugin', 'hash', null ),
			array( 'mu_plugin', 'form', 'other' ),
			array( 'entries', 'ref', 'plugin:akismet#2' ),
			array( 'entries', 'unit', 'secret:akismet' ),
			array( 'entries', 'unit', 'plugin:' ),
			array( 'entries', 'seq', 0 ),
			array( 'entries', 'entries', array() ),
			array( 'entries', 'entries', array( array( 'a.php', 3, str_repeat( 'c', 64 ) ) ) ),
			array( 'entries', 'entries', array( array( '', 3, str_repeat( 'c', 64 ), 1 ) ) ),
			array( 'entries', 'entries', array( array( 'a.php', -3, str_repeat( 'c', 64 ), 1 ) ) ),
			array( 'entries', 'entries', array( array( 'a.php', 3, str_repeat( 'C', 64 ), 1 ) ) ),
			array( 'entries', 'entries', array_fill( 0, 501, array( 'a.php', 3, str_repeat( 'c', 64 ), 1 ) ) ),
			array( 'theme_choice', 'ref', 'theme' ),
			array( 'theme_choice', 'stylesheet', 3 ),
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

	public function testANameThatIsNoPlainTextIsShownByItsSha256(): void {
		self::assertSame( 'akismet/akismet.php', AB_MCP_Sync_Env::name( 'akismet/akismet.php' ) );
		self::assertSame( 'sha256:' . hash( 'sha256', "\xffA" ), AB_MCP_Sync_Env::name( "\xffA" ), 'Not UTF-8.' );
		self::assertSame( 'sha256:' . hash( 'sha256', "a\n" ), AB_MCP_Sync_Env::name( "a\n" ), 'A control character.' );
		self::assertSame( str_repeat( 'a', 1024 ), AB_MCP_Sync_Env::name( str_repeat( 'a', 1024 ) ), 'As long as a line holds.' );
		self::assertSame( 'sha256:' . hash( 'sha256', str_repeat( 'a', 1025 ) ), AB_MCP_Sync_Env::name( str_repeat( 'a', 1025 ) ), 'Longer than a line holds.' );
		foreach ( array( "\xffA", str_repeat( 'a', 1025 ), str_repeat( 'é', 600 ) ) as $odd ) {
			self::assertSame( AB_MCP_Sync_Env::name( $odd ), AB_MCP_Sync_Env::name( AB_MCP_Sync_Env::name( $odd ) ), 'The form it gives is one a line holds as it is.' );
		}
		self::assertTrue( AB_MCP_Sync_Env::shaped( self::unit( 'plugin', AB_MCP_Sync_Env::name( "\xffA" ) ) ) );
	}

	public function testAVersionLongerThanALineHoldsIsKeptByItsSha256AndNotShown(): void {
		$long = str_repeat( '1.', 512 ) . '9';
		self::assertSame( 1025, strlen( $long ) );
		$this->file( 'plugins/long/long.php', "<?php\n/*\n * Plugin Name: Long\n * Version: $long\n */" );
		$this->file( 'themes/long/style.css', "/*\nTheme Name: Long\nVersion: $long\n*/" );
		$plugins = AB_MCP_Sync_Env::plugin_files( $this->dir . '/plugins' );
		$themes  = AB_MCP_Sync_Env::theme_dirs( array( $this->dir . '/themes' ) );
		self::assertSame( array( 'long/long.php' => 'sha256:' . hash( 'sha256', $long ) ), $plugins );
		self::assertSame( array( 'long' => 'sha256:' . hash( 'sha256', $long ) ), $themes );
		$plugin = self::unit( 'plugin', 'long', array( 'version' => $plugins['long/long.php'] ) );
		$theme  = self::unit( 'theme', 'long', array( 'version' => $themes['long'] ) );
		self::assertTrue( AB_MCP_Sync_Env::shaped( $plugin ), 'The line keeps its structure, so the package stays valid.' );
		self::assertTrue( AB_MCP_Sync_Env::shaped( $theme ) );
		$e = self::one( $plugin, $plugin );
		self::assertSame( array( 'equal', 'not shown', 'not shown' ), array( $e['result'], $e['staging']['version'], $e['live']['version'] ) );
		$e = self::one( $plugin, array( 'version' => '2.0', 'hash' => str_repeat( 'd', 64 ) ) + $plugin );
		self::assertSame( array( 'differs', array( 'files' ), 'not shown', '2.0' ), array( $e['result'], $e['differs'], $e['staging']['version'], $e['live']['version'] ) );
		self::assertStringNotContainsString( '1.1.1.1', (string) json_encode( $e ) );
		$wp = array( 'env' => 'core', 'ref' => 'wordpress', 'value' => AB_MCP_Sync_Env::name( $long ) );
		$e  = self::one( $wp, array( 'value' => '7.1.3' ) + $wp );
		self::assertSame( array( 'differs', 'not shown', '7.1.3' ), array( $e['result'], $e['staging']['value'], $e['live']['value'] ), 'A version of WordPress longer than a line holds: not shown either.' );
	}

	/* --------------------------------------------- names a credential holds */

	public function testANameWithACredentialWrittenIntoItIsHeld(): void {
		foreach ( array( 'password=', 'password=EXAMPLE_SECRET.php', 'my-plugin/api_key=EXAMPLE_SECRET.php', 'token%3DEXAMPLE_SECRET', base64_encode( 'password=EXAMPLE_SECRET' ) . '.php', '{"secret":"EXAMPLE_SECRET"}' ) as $name ) {
			self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( $name ), $name );
		}
		self::assertSame( 'unreadable_name', AB_MCP_Sync_Env::held( str_repeat( '[', 80 ) . str_repeat( ']', 80 ) ), 'Deeper than the check reads.' );
		$nested = 'password=x';
		for ( $i = 0; $i < 8; $i++ ) {
			$nested = base64_encode( $nested );
		}
		self::assertSame( 'unreadable_name', AB_MCP_Sync_Env::held( $nested ), 'Deeper than the net reads.' );
		foreach ( array( 'akismet/akismet.php', 'reset-password/reset-password.php', 'woocommerce_api_keys', 'wp-oauth-server/wp-oauth.php', '2026/10/photo-150x150.jpg', 'gf_form', 'twentytwentyfive', 'secret-santa.php' ) as $name ) {
			self::assertNull( AB_MCP_Sync_Env::held( $name ), $name . ': an ordinary name, shown as it is.' );
		}
		self::assertNull( AB_MCP_Sync_Env::held( 'vault=1.php' ) );
		add_filter( 'ab_mcp_redact_keys', static fn( array $keys ): array => array_merge( $keys, array( 'vault' ) ) );
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( 'vault=1.php' ), 'With the names of ab_mcp_redact_keys.' );
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( 'vault=1.php', array( 'vault' ) ) );
		self::assertNull( AB_MCP_Sync_Env::held( 'vault=1.php', array() ), 'The names it is given.' );
	}

	/** A line of a held name, as read() writes it. */
	private static function held_mu( string $name, string $sha = 'a' ): array {
		$line = array( 'held' => 'credential_field' ) + self::file_unit( 'mu_plugin', AB_MCP_Sync_Env::hashed( $name ), $sha );
		ksort( $line );
		return $line;
	}

	public function testALineOfAHeldNameCarriesItsSha256AndWhyOnly(): void {
		$line = self::held_mu( 'password=EXAMPLE_SECRET.php' );
		self::assertTrue( AB_MCP_Sync_Env::shaped( $line ) );
		$table = array( 'env' => 'table', 'held' => 'unreadable_name', 'ref' => AB_MCP_Sync_Env::hashed( "\xff" ), 'rows' => 1, 'schema' => null );
		self::assertTrue( AB_MCP_Sync_Env::shaped( $table ) );
		foreach ( array( 'plugin', 'theme', 'language', 'font', 'upload' ) as $env ) {
			self::assertTrue( AB_MCP_Sync_Env::shaped( array( 'held' => 'credential_field' ) + self::unit( $env, AB_MCP_Sync_Env::hashed( 'x=y' ) ) ), $env );
		}
		$wrong = array(
			'its name as it is'    => array( 'ref' => 'password=EXAMPLE_SECRET.php' ) + $line,
			'a code it never gives' => array( 'held' => 'secret' ) + $line,
			'the code of a key'    => array( 'held' => 'credential_name' ) + $line,
			'held null'            => array( 'held' => null ) + $line,
		);
		foreach ( $wrong as $case => $bad ) {
			self::assertFalse( AB_MCP_Sync_Env::shaped( $bad ), $case );
		}
		self::assertFalse( AB_MCP_Sync_Env::shaped( array( 'env' => 'core', 'held' => 'credential_field', 'ref' => 'wordpress', 'value' => '7.1.3' ) ), 'Only a name of the site is held.' );
		self::assertFalse( AB_MCP_Sync_Env::shaped( array( 'bytes' => 1, 'env' => 'dropin', 'held' => 'credential_field', 'ref' => 'db.php', 'sha256' => null ) ) );
	}

	public function testAHeldNameIsOneEntryByItsSha256OnBothSidesAndShownNowhere(): void {
		$name = 'password=EXAMPLE_SECRET.php';
		$held = self::held_mu( $name );
		$e    = self::one( $held, $held );
		self::assertSame( array( 'mu_plugin', AB_MCP_Sync_Env::hashed( $name ), 'equal', 'credential_field' ), array( $e['subtype'], $e['ref'], $e['result'], $e['held'] ) );
		self::assertStringContainsString( 'held: credential_field', $e['words'] );
		self::assertSame( array(), array_intersect( array( 'held', 'hash', 'sha256', 'mtime' ), array_keys( $e['staging'] ) ), 'No held in a side, no hash.' );
		self::assertSame( 3, $e['staging']['bytes'] );
		$e = self::one( $held, self::held_mu( $name, 'b' ) );
		self::assertSame( array( 'differs', 'locked' ), array( $e['result'], $e['action'] ), 'Its file compared by its SHA-256 still.' );

		// One site holds it by its rules, the other not: one entry, by its SHA-256.
		$plain = array( 'ref' => $name ) + $held;
		unset( $plain['held'] );
		foreach ( array( 'held on staging' => array( $held, $plain ), 'held here' => array( $plain, $held ) ) as $case => list( $s, $l ) ) {
			$out = AB_MCP_Sync_Env::compare( array( $s, self::whole() ), array( $l, self::whole() ) );
			self::assertCount( 1, $out['entries'], $case );
			self::assertSame( array( AB_MCP_Sync_Env::hashed( $name ), 'equal', 'credential_field' ), array( $out['entries'][0]['ref'], $out['entries'][0]['result'], $out['entries'][0]['held'] ), $case );
			self::assertStringNotContainsString( 'EXAMPLE_SECRET', (string) json_encode( $out ), $case );
		}
		$other = array( 'ref' => 'other.php' ) + $plain;
		$out   = AB_MCP_Sync_Env::compare( array( $held, self::whole() ), array( $other, self::whole() ) );
		self::assertSame( array( 'other.php', AB_MCP_Sync_Env::hashed( $name ) ), array_column( $out['entries'], 'ref' ), 'A line of another name stays as it is.' );
		$e = self::one( $held, null );
		self::assertSame( array( 'only_staging', 'credential_field' ), array( $e['result'], $e['held'] ) );
		self::assertArrayNotHasKey( 'held', self::one( self::plugin( '1' ), self::plugin( '1' ) ), 'An ordinary name: no held.' );

		// A table is decided by the environment itself: its held name the same way.
		$table = array( 'env' => 'table', 'held' => 'credential_field', 'ref' => AB_MCP_Sync_Env::hashed( 'abenv_token=x' ), 'rows' => 1, 'schema' => str_repeat( 'c', 64 ) );
		$list  = array( array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' ) );
		$e     = self::one( $table, $table, $list, $list );
		self::assertSame( array( 'counted', 'credential_field' ), array( $e['result'], $e['held'] ) );
		self::assertStringContainsString( 'held: credential_field', $e['words'] );
		self::assertSame( array( 'rows' => 1 ), $e['staging'], 'No held in a side, no hash.' );
	}

	public function testALineTheImportHeldIsShownByItsSha256(): void {
		$name  = 'vault=1.php';
		$line  = self::file_unit( 'mu_plugin', $name, 'a' );
		$other = self::plugin( '1' );
		self::assertNull( AB_MCP_Sync_Env::held_line( $line, array() ) );
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held_line( $line, array( 'vault' ) ), 'By the rules of this site.' );
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held_line( self::held_mu( 'x=1.php' ), array() ), 'Held on the staging site already.' );
		self::assertNull( AB_MCP_Sync_Env::held_line( array( 'env' => 'core', 'ref' => 'locale', 'value' => 'token=x' ), array() ), 'A ref of a fixed list is no name of the site.' );
		self::assertNull( AB_MCP_Sync_Env::held_line( array( 'ref' => AB_MCP_Sync_Env::hashed( 'long' ) ) + $line, array( 'vault' ) ), 'A name by its SHA-256 already.' );
		$list = array( array( 'mu_plugin', hash( 'sha256', \AB_MCP_Sync_Canon::encode( $name ) ), 'credential_field' ) );
		$here = AB_MCP_Sync_Env::held_here( array( $line, $other ), $list );
		$want = array( 'held' => 'credential_field', 'ref' => AB_MCP_Sync_Env::hashed( $name ) ) + $line;
		ksort( $want );
		self::assertSame( $want, $here[0] );
		self::assertTrue( AB_MCP_Sync_Env::shaped( $here[0] ) );
		self::assertSame( $other, $here[1], 'A line the list does not name stays.' );
		self::assertSame( array( $other ), AB_MCP_Sync_Env::held_here( array( $other ), array( array( 'plugin', hash( 'sha256', \AB_MCP_Sync_Canon::encode( 'other' ) ), 'credential_field' ) ) ) );
		self::assertSame( array( array( 'env' => 'core', 'ref' => 'locale', 'value' => 'x' ) ), AB_MCP_Sync_Env::held_here( array( array( 'env' => 'core', 'ref' => 'locale', 'value' => 'x' ) ), array( array( 'core', hash( 'sha256', \AB_MCP_Sync_Canon::encode( 'locale' ) ), 'credential_field' ) ) ), 'Only a name of the site.' );
	}

	public function testAConstantTheRulesOfThisSiteDoNotShowIsNotShownFromTheStagingSiteEither(): void {
		// The staging site shows it, this site redacts its name
		// (ab_mcp_redact_keys): its value is not shown here either (GitHub-Codex,
		// PR #136, P2), as constants() leaves it out here.
		$line  = array( 'defined' => true, 'env' => 'host', 'ref' => 'constant:WP_DEFAULT_THEME', 'value' => 'EXAMPLE_THEME_OF_STAGING' );
		$other = array( 'defined' => true, 'env' => 'host', 'ref' => 'constant:WP_DEBUG', 'value' => true );
		$php   = array( 'defined' => true, 'env' => 'host', 'ref' => 'php', 'value' => '8.3.1' );
		$named = self::plugin( '1' );
		$named['ref'] = 'constant:WP_DEFAULT_THEME';
		$here  = AB_MCP_Sync_Env::held_here( array( $line, $other, $php, $named ), array(), array( 'wp_default_theme' ) );
		self::assertSame( array_replace( $line, array( 'value' => 'not shown' ) ), $here[0], 'Defined, its value not shown.' );
		self::assertTrue( AB_MCP_Sync_Env::shaped( $here[0] ) );
		self::assertSame( array( $other, $php, $named ), array_slice( $here, 1 ), 'A constant this site shows, a value of the host and a name of the site stay.' );
		self::assertSame( array( $line ), AB_MCP_Sync_Env::held_here( array( $line ), array(), array() ), 'Without the rule, as it is.' );
		add_filter( 'ab_mcp_redact_keys', static fn( array $keys ): array => array_merge( $keys, array( 'WP_DEFAULT_THEME' ) ) );
		$here = AB_MCP_Sync_Env::held_here( array( $line ), array() );
		self::assertSame( 'not shown', $here[0]['value'], 'The rules of this site where none are given.' );
		$e = self::one( $here[0], null );
		self::assertSame( array( 'only_staging', array( 'defined' => true, 'value' => 'not shown' ) ), array( $e['result'], $e['staging'] ), 'Only on staging, as this site leaves it out.' );
		self::assertStringNotContainsString( 'EXAMPLE_THEME_OF_STAGING', (string) json_encode( $e ) );
	}

	public function testANameWithACredentialAfterAColonIsHeldAsOneAfterAnEqualsSign(): void {
		foreach ( array( 'password:EXAMPLE_SECRET.php', 'my-plugin/api_key:EXAMPLE_SECRET.php', 'token : EXAMPLE_SECRET', 'abenv_password:EXAMPLE_SECRET', 'keep/client_secret:EXAMPLE_SECRET/f.txt', '-password:EXAMPLE_SECRET.php', '.token:EXAMPLE_SECRET.php', base64_encode( 'password:EXAMPLE_SECRET' ) . '.php' ) as $name ) {
			self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( $name ), $name );
		}
		foreach ( array( 'reset-password/reset-password.php', 'wp-oauth-server/wp-oauth-server.php', 'woocommerce_api_keys', 'password-protected/password-protected.php', 'note:EXAMPLE.php', 'a:b:c' ) as $name ) {
			self::assertNull( AB_MCP_Sync_Env::held( $name ), $name . ': an ordinary name, shown as it is.' );
		}
		self::assertNull( AB_MCP_Sync_Env::held( 'vault:EXAMPLE_SECRET.php', array() ) );
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( 'vault:EXAMPLE_SECRET.php', array( 'vault' ) ), 'With the names of ab_mcp_redact_keys.' );
		// The net over a name of the inventory keeps its rule: there a title in
		// prose («Password: reset») is no field of a credential.
		self::assertNull( \AB_MCP_Sync_Net::over_name( 'password:EXAMPLE_SECRET', array() ) );
		self::assertSame( 'credential', \AB_MCP_Sync_Net::over_name( 'password:EXAMPLE_SECRET', array(), true ) );
	}

	public function testANameWithTheNameOfACredentialBeforeAnEqualsSignOrAColonIsHeldWhateverFollows(): void {
		$held = array(
			'note:password:EXAMPLE_SECRET.php',          // A colon before it (Codex, round 3 of branch 7, A).
			'password:;EXAMPLE_SECRET.php',              // A character after it that ends a value (round 3, A).
			'x.token = EXAMPLE_SECRET',                  // White space around it.
			'api_key:EXAMPLE_SECRET',                    // A folder (a theme).
			'api_key:EXAMPLE_SECRET/api.php',            // A folder (a plugin).
			'keep/note:password:EXAMPLE_SECRET/f.txt',   // A folder below a folder.
			'PASSWORD=EXAMPLE_SECRET',                   // Upper case, as the rest of the check reads it.
			'Password : EXAMPLE_SECRET',
			'accessToken:EXAMPLE_SECRET.php',            // In camelCase.
			'my-oauth-server:EXAMPLE_SECRET.php',        // The name of a credential inside the name before it.
			'password:',                                 // Nothing after it.
			'password=',
			'a=b:token=;EXAMPLE_SECRET',
			'xpassword:EXAMPLE_SECRET',                  // At any place in the name.
			'xpassWord:EXAMPLE_SECRET',                  // In lower case only.
			'password:x:b.php',                          // Not only at the last = or :.
			'keep/oauth_server:EXAMPLE_SECRET',          // The last word before it.
			'keep/oauth_server :x',
			'xpassWord :x',
			'my-auth:EXAMPLE_SECRET',                    // A name of EXTRA_NAMES.
			rawurlencode( 'password:;EXAMPLE_SECRET' ) . '.php', // Encoded, in every layer.
			base64_encode( 'note:password:EXAMPLE_SECRET' ) . '.php',
			'reset-password:guide.php',                  // An ordinary name held as well (fail closed, rare).
		);
		foreach ( $held as $name ) {
			self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( $name, array() ), $name );
		}
		foreach ( array( 'reset-password', 'wp-oauth-server', 'woocommerce_api_keys', 'password-protected', 'note:EXAMPLE.php', 'my-token-plugin', 'a:b:c', 'password-x:EXAMPLE.php', 'tokens.php', 'akismet/akismet.php' ) as $name ) {
			self::assertNull( AB_MCP_Sync_Env::held( $name, array() ), $name . ': an ordinary name, shown as it is.' );
		}
		// The content check reads «&» with the decoder of the core only (on a
		// site: the integration suite); the net holds nothing in it.
		self::assertFalse( \AB_MCP_Sync_Net::keyed( 'R&D notes.pdf', array() ) );
		self::assertNull( \AB_MCP_Sync_Net::over_name( 'R&D notes.pdf', array(), true ) );
		// The names of ab_mcp_redact_keys, in the spellings the check reads alike.
		foreach ( array( 'note:vault:EXAMPLE_SECRET.php', 'vault:;EXAMPLE_SECRET', 'my-vault=EXAMPLE_SECRET', 'myVault : EXAMPLE_SECRET', 'MY_VAULT:x', 'my vault:x' ) as $name ) {
			self::assertNull( AB_MCP_Sync_Env::held( $name, array() ), $name );
			self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( $name, array( 'vault', 'my_vault' ) ), $name . ': with the names of ab_mcp_redact_keys.' );
		}
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( 'my vault:x', array( 'my_vault' ) ), 'A name of ab_mcp_redact_keys as normalize_key() reads the text before it.' );
		self::assertNull( AB_MCP_Sync_Env::held( 'vault-plugin/vault.php', array( 'vault' ) ), 'Without = or : after it, an ordinary name.' );
		self::assertNull( AB_MCP_Sync_Env::held( 'note:EXAMPLE.php', array( '' ) ), 'An empty name is no name of a credential.' );
		// The net over a name of the inventory keeps its rule.
		foreach ( array( 'note:password:EXAMPLE_SECRET', 'password:;EXAMPLE_SECRET', 'Password: reset' ) as $text ) {
			self::assertNull( \AB_MCP_Sync_Net::over_name( $text, array() ), $text );
			self::assertSame( 'credential', \AB_MCP_Sync_Net::over_name( $text, array(), true ), $text );
		}
	}

	public function testANameWithTheNameOfACredentialBeforeASemicolonOrAQuoteIsHeldWhateverFollows(): void {
		$held = array(
			'a:1:{s:8:"password";s:14:"EXAMPLE_SECRET";}.php', // Serialized: the content check reads it as clean.
			'a:1:{s:8:"password";s:14:"EXAMPLE_SECRET";}',
			'{"password" : "EXAMPLE_SECRET"}',                 // A quoted key, white space after it.
			"{'api_key' : 'EXAMPLE_SECRET'}.php",
			'password;EXAMPLE_SECRET.php',                     // A semicolon right after it.
			'x.token ; EXAMPLE_SECRET',
			'password"EXAMPLE_SECRET.php',
			"keep/client_secret'EXAMPLE_SECRET/f.txt",
			'note;password;EXAMPLE_SECRET.php',                // Each sign on its own.
			'keep/oauth_server"x',                             // The last word before it.
			base64_encode( 'a:1:{s:8:"password";s:14:"EXAMPLE_SECRET";}' ) . '.php', // Encoded, in every layer.
			'reset-password;v2.php',                           // Ordinary names held as well (fail closed, rare).
			"O'Reilly password's.pdf",
		);
		foreach ( $held as $name ) {
			self::assertSame( 'credential', \AB_MCP_Sync_Net::over_name( $name, array(), true ), $name . ': by the net.' );
			self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( $name, array() ), $name );
		}
		self::assertTrue( \AB_MCP_Sync_Net::keyed( '<input name="password" value="EXAMPLE_SECRET">.php', array() ), 'A quoted name of a field of a form, whatever the content check reads.' );
		foreach ( array( 'reset-password', 'wp-oauth-server', 'woocommerce_api_keys', 'password-protected', 'my-token-plugin', 'note:EXAMPLE.php', 'password-x:EXAMPLE.php', 'note;EXAMPLE.php', "O'Reilly notes.pdf", 'say "hello".txt' ) as $name ) {
			self::assertFalse( \AB_MCP_Sync_Net::keyed( $name, array() ), $name );
			self::assertNull( AB_MCP_Sync_Env::held( $name, array() ), $name . ': an ordinary name, shown as it is.' );
		}
		self::assertFalse( \AB_MCP_Sync_Net::keyed( 'R&D notes.php', array() ) );
		self::assertNull( \AB_MCP_Sync_Net::over_name( 'R&D notes.php', array(), true ) );
		self::assertNull( AB_MCP_Sync_Env::held( 'vault;EXAMPLE_SECRET.php', array() ) );
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( 'vault;EXAMPLE_SECRET.php', array( 'vault' ) ), 'With the names of ab_mcp_redact_keys.' );
		// The net over a name of the inventory keeps its rule, the rule of a
		// value: a sign after the name holds nothing there; a key of the
		// serialized format with a value counts in a value since branch 11b
		// (the steering, 09.11.2026), so in a name of the inventory as well.
		self::assertNull( \AB_MCP_Sync_Net::over_name( 'password;EXAMPLE_SECRET.php', array() ) );
		self::assertSame( 'credential', \AB_MCP_Sync_Net::over_name( 'a:1:{s:8:"password";s:14:"EXAMPLE_SECRET";}.php', array() ) );
	}

	public function testAPlaceHoldsNoNameOnlyTheSha256OfEachFolderAndOfTheWholePath(): void {
		self::assertNull( AB_MCP_Sync_Env::place( null ) );
		$place = AB_MCP_Sync_Env::place( 'password=/EXAMPLE_SECRET.php' );
		self::assertSame( array( hash( 'sha256', 'password=' ), hash( 'sha256', 'password=/EXAMPLE_SECRET.php' ) ), $place );
		$ordinary = AB_MCP_Sync_Env::place( '2026/10/photo.jpg' );
		self::assertSame( array( hash( 'sha256', '2026' ), hash( 'sha256', '2026/10' ), hash( 'sha256', '2026/10/photo.jpg' ) ), $ordinary, 'An ordinary path the same way.' );
		$table = AB_MCP_Sync_Env::place( 'abenv_password:EXAMPLE_SECRET', false );
		self::assertSame( array( hash( 'sha256', 'abenv_password:EXAMPLE_SECRET' ) ), $table, 'A table by the SHA-256 of its whole name.' );
		self::assertSame( array( hash( 'sha256', 'odd/table' ) ), AB_MCP_Sync_Env::place( 'odd/table', false ), 'A «/» in it as well.' );
		foreach ( array( 'EXAMPLE_SECRET', 'password', 'photo', '2026', 'odd' ) as $word ) {
			self::assertStringNotContainsString( $word, (string) json_encode( array( $place, $ordinary, $table ) ) );
		}
		$names = static function ( string $path ): array {
			return array(
				''          => array( 'a', 'password=' ),
				'password=' => array( 'EXAMPLE_SECRET.php', 'z.php' ),
			)[ $path ] ?? array();
		};
		self::assertSame( 'password=/EXAMPLE_SECRET.php', AB_MCP_Sync_Env::found( $place, $names ) );
		self::assertSame( 'password=', AB_MCP_Sync_Env::found( AB_MCP_Sync_Env::place( 'password=' ), $names ), 'A folder.' );
		self::assertNull( AB_MCP_Sync_Env::found( AB_MCP_Sync_Env::place( 'password=/gone.php' ), $names ), 'The entry gone since.' );
		self::assertNull( AB_MCP_Sync_Env::found( AB_MCP_Sync_Env::place( 'gone/z.php' ), $names ), 'Its folder gone since.' );
		self::assertNull( AB_MCP_Sync_Env::found( array( hash( 'sha256', 'password=/z.php' ) ), $names ), 'Each folder by its own SHA-256, not the whole path at the top.' );
		self::assertNull( AB_MCP_Sync_Env::found( array( hash( 'sha256', 'gone' ), hash( 'sha256', 'a' ) ), $names ), 'A folder gone: none, not a name of the same SHA-256 elsewhere.' );
		$tables = static fn() => array( 'abenv_a', 'abenv_password:EXAMPLE_SECRET', 'odd/table' );
		self::assertSame( 'abenv_password:EXAMPLE_SECRET', AB_MCP_Sync_Env::found( $table, $tables ) );
		self::assertSame( 'odd/table', AB_MCP_Sync_Env::found( AB_MCP_Sync_Env::place( 'odd/table', false ), $tables ) );
		self::assertNull( AB_MCP_Sync_Env::found( AB_MCP_Sync_Env::place( 'abenv_gone', false ), $tables ), 'A table gone since.' );
	}

	/** The names in a folder of this test, by strcmp(), as the walk reads them. */
	private function names_in(): callable {
		$dir = $this->dir;
		return static function ( string $rel ) use ( $dir ): array {
			$names = array_values( array_diff( (array) scandir( '' === $rel ? $dir : $dir . '/' . $rel ), array( '.', '..' ) ) );
			usort( $names, 'strcmp' );
			return $names;
		};
	}

	public function testAWalkOverPartsGoesOnFromItsPlaceByItsSha256AndNotWhereItIsGone(): void {
		for ( $i = 0; $i < 300; $i++ ) {
			$this->file( sprintf( 'password=/EXAMPLE_SECRET/%03d.txt', $i ), 'x' );
		}
		$this->file( 'a/x.txt', 'xx' );
		$this->file( 'z/y.txt', 'yyy' );
		$whole = self::acc();
		self::assertSame( 'done', AB_MCP_Sync_Env::walk( $this->dir, null, $whole, PHP_INT_MAX, self::none() ) );

		// Each look at the clock a second later, two seconds a part.
		$now                    = 0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$now ) {
			return (float) ++$now;
		};
		$acc    = self::acc();
		$place  = null;
		$places = array();
		$parts  = 0;
		do {
			$after = null === $place ? null : AB_MCP_Sync_Env::found( $place, $this->names_in() );
			self::assertTrue( null === $place || null !== $after, 'Found again.' );
			$acc['after'] = $after;
			$walked       = AB_MCP_Sync_Env::walk( $this->dir, $after, $acc, $now + 2, self::none() );
			$place        = 'stopped' === $walked ? AB_MCP_Sync_Env::place( $acc['after'] ) : null;
			$places[]     = $place;
			++$parts;
		} while ( 'stopped' === $walked && $parts < 100 );
		self::assertSame( 'done', $walked );
		self::assertSame( array( $whole['files'], $whole['bytes'] ), array( $acc['files'], $acc['bytes'] ), 'Over parts, each file counted once.' );
		$inside = array_values( array_filter( $places, static fn( $p ) => is_array( $p ) && 3 === count( $p ) && hash( 'sha256', 'password=' ) === $p[0] ) );
		self::assertNotEmpty( $inside, 'Parts that stopped in that folder.' );
		self::assertStringNotContainsString( 'EXAMPLE_SECRET', (string) json_encode( $places ) );
		self::assertStringNotContainsString( '.txt', (string) json_encode( $places ), 'No name of an ordinary file either.' );

		// The file a part stopped at gone since: the place is found no more.
		$at = AB_MCP_Sync_Env::found( $inside[0], $this->names_in() );
		self::assertIsString( $at );
		unlink( $this->dir . '/' . $at );
		self::assertNull( AB_MCP_Sync_Env::found( $inside[0], $this->names_in() ) );
	}

	public function testAStateWithANameThatIsNoUtf8ReadsBackAsOneReadWrites(): void {
		$state = array(
			'after'    => AB_MCP_Sync_Env::place( "dir/\xffname.txt" ),
			'attached' => 0,
			'bytes'    => 3,
			'files'    => 1,
			'seq'      => 2,
			'skipped'  => 0,
			'step'     => 5,
			'walk'     => str_repeat( 'ab', 16 ),
		);
		$round = json_decode( \AB_MCP_Sync_Canon::encode( $state ), true );
		self::assertSame( $state, $round, 'The canonical form keeps it as it is: no part in the byte form.' );
		self::assertTrue( AB_MCP_Sync_Env::state_shaped( $round ) );
		self::assertSame( "dir/\xffname.txt", AB_MCP_Sync_Env::found( $round['after'], static fn( string $p ): array => '' === $p ? array( 'dir' ) : array( "\xffname.txt" ) ) );
	}

	public function testTheMainFilesOfAPluginAreHeldByTheRulesOfBothSites(): void {
		// GitHub-Codex on free#147, P1: the main files of a plugin and those
		// that run, held as the paths of entries and the theme that runs are.
		$name  = 'deploy_marker=EXAMPLE_SECRET.php';
		$line  = self::unit( 'plugin', 'shop', array( 'mains' => array( $name, 'main.php' ), 'active' => array( $name ) ) );
		self::assertSame( array( $line ), AB_MCP_Sync_Env::held_here( array( $line ), array(), array() ), 'Without the rule of this site, as it is.' );
		$here = AB_MCP_Sync_Env::held_here( array( $line ), array(), array( 'deploy_marker' ) );
		self::assertSame( array( AB_MCP_Sync_Env::hashed( $name ), 'main.php' ), $here[0]['mains'], 'The rule of this site holds a main file.' );
		self::assertSame( array( AB_MCP_Sync_Env::hashed( $name ) ), $here[0]['active'] );
		self::assertTrue( AB_MCP_Sync_Env::shaped( $here[0] ) );
		$entry = AB_MCP_Sync_Env::compare( array( $here[0], self::whole() ), array( $here[0], self::whole() ) )['entries'][0];
		self::assertSame( 'equal', $entry['result'] );
		self::assertStringNotContainsString( 'EXAMPLE_SECRET', (string) json_encode( $entry ), 'Not in the report.' );
		// A main file the staging site holds: held on this site before it is stored.
		$held = AB_MCP_Sync_Env::held_names( array( $here[0] ) );
		self::assertSame( array( AB_MCP_Sync_Env::hashed( $name ) => 'credential_field' ), $held );
		$there = AB_MCP_Sync_Env::held_there( array( $line ), $held );
		self::assertSame( array( array( AB_MCP_Sync_Env::hashed( $name ), 'main.php' ), array( AB_MCP_Sync_Env::hashed( $name ) ) ), array( $there[0]['mains'], $there[0]['active'] ) );
		self::assertStringNotContainsString( 'EXAMPLE_SECRET', (string) json_encode( $there ) );
	}

	public function testALineOfThisSiteWhoseNameTheStagingSiteHoldsIsHeldBeforeItIsStored(): void {
		$name    = 'vault=EXAMPLE_SECRET.php';
		$table   = 'abenv_vault=EXAMPLE_SECRET';
		$staging = array(
			self::held_mu( $name ),
			self::plugin( '1' ),
			array( 'env' => 'table', 'held' => 'unreadable_name', 'ref' => AB_MCP_Sync_Env::hashed( $table ), 'rows' => 1, 'schema' => null ),
			array( 'env' => 'core', 'ref' => 'locale', 'value' => 'de_CH' ),
			self::unit( 'plugin', AB_MCP_Sync_Env::name( "\xffA" ) ),
		);
		$held = AB_MCP_Sync_Env::held_names( $staging );
		$want = array(
			AB_MCP_Sync_Env::hashed( $name )  => 'credential_field',
			AB_MCP_Sync_Env::hashed( $table ) => 'unreadable_name',
		);
		ksort( $want, SORT_STRING );
		self::assertSame( $want, $held, 'The names the staging site holds, by their SHA-256 with why, in one order.' );
		self::assertSame( $held, AB_MCP_Sync_Env::held_names( array_reverse( $staging ) ), 'Whatever the order of the lines; a name by its SHA-256 that is no text is held by nothing.' );
		$mu   = self::file_unit( 'mu_plugin', $name, 'a' );
		$live = array(
			$mu,
			array( 'ref' => 'other.php' ) + $mu,
			self::unit( 'plugin', $name ),
			array( 'env' => 'table', 'ref' => $table, 'rows' => 2, 'schema' => null ),
			array( 'env' => 'core', 'ref' => 'locale', 'value' => 'de_CH' ),
			self::held_mu( 'x=1.php' ),
			array( 'held' => 'unreadable_name' ) + self::held_mu( $name ),
		);
		$out = AB_MCP_Sync_Env::held_there( $live, $held );
		$want = array( 'held' => 'credential_field', 'ref' => AB_MCP_Sync_Env::hashed( $name ) ) + $mu;
		ksort( $want );
		self::assertSame( $want, $out[0], 'By its SHA-256 with why, before it is stored.' );
		self::assertSame( $live[1], $out[1], 'Another name stays as it is.' );
		self::assertSame( array( 'credential_field', AB_MCP_Sync_Env::hashed( $name ) ), array( $out[2]['held'], $out[2]['ref'] ), 'The same name of another kind as well.' );
		self::assertSame( array( 'unreadable_name', AB_MCP_Sync_Env::hashed( $table ) ), array( $out[3]['held'], $out[3]['ref'] ) );
		self::assertSame( array_slice( $live, 4 ), array_slice( $out, 4 ), 'No name of the site, or held here already (for its own reason): as it is.' );
		$odd     = "\xffvault=EXAMPLE_SECRET.php";
		$dropin  = array( 'bytes' => 1, 'env' => 'dropin', 'ref' => 'db.php', 'sha256' => null );
		$by_hash = array( 'ref' => AB_MCP_Sync_Env::name( $odd ) ) + $mu;
		$there   = array(
			AB_MCP_Sync_Env::hashed( $odd )     => 'unreadable_name',
			AB_MCP_Sync_Env::hashed( 'db.php' ) => 'credential_field',
		);
		$out = AB_MCP_Sync_Env::held_there( array( $by_hash, $dropin ), $there );
		self::assertSame( array( 'unreadable_name', AB_MCP_Sync_Env::hashed( $odd ) ), array( $out[0]['held'] ?? null, $out[0]['ref'] ), 'A name by its SHA-256 already, held by it.' );
		self::assertSame( $dropin, $out[1], 'A drop-in is no name of the site.' );
		foreach ( $out as $line ) {
			self::assertTrue( AB_MCP_Sync_Env::shaped( $line ), (string) json_encode( $line ) );
		}
		self::assertStringNotContainsString( 'EXAMPLE_SECRET', (string) json_encode( $out ) );
		self::assertSame( $live, AB_MCP_Sync_Env::held_there( $live, array() ), 'Nothing held there: as it is.' );
	}

	public function testAStateIsOnlyOneReadWrites(): void {
		$state = array(
			'after'    => array( hash( 'sha256', 'gf_form' ) ),
			'attached' => 0,
			'bytes'    => 0,
			'files'    => 0,
			'seq'      => 2,
			'skipped'  => 0,
			'step'     => 1,
			'walk'     => str_repeat( 'ab', 16 ),
		);
		self::assertTrue( AB_MCP_Sync_Env::state_shaped( $state ) );
		self::assertTrue( AB_MCP_Sync_Env::state_shaped( array( 'after' => array( str_repeat( 'a', 64 ), str_repeat( 'c', 64 ), str_repeat( 'b', 64 ) ) ) + $state ) );
		self::assertTrue( AB_MCP_Sync_Env::state_shaped( array( 'after' => null ) + $state ) );
		$wrong = array(
			array( 'seq', 0 ),
			array( 'step', count( AB_MCP_Sync_Env::STEPS ) ),
			array( 'step', -1 ),
			array( 'walk', 'x' ),
			array( 'after', '' ),
			array( 'after', 3 ),
			array( 'after', 'gf_form' ),
			array( 'after', str_repeat( 'a', 64 ) ),
			array( 'after', array() ),
			array( 'after', array( 'gf_form' ) ),
			array( 'after', array( '' ) ),
			array( 'after', array( str_repeat( 'a', 63 ) ) ),
			array( 'after', array( str_repeat( 'a', 64 ) . "\n" ) ),
			array( 'after', array( str_repeat( 'A', 64 ) ) ),
			array( 'after', array( 'sha256:' . str_repeat( 'a', 64 ) ) ),
			array( 'after', array( 'a' => str_repeat( 'a', 64 ) ) ),
			array( 'after', array( 1 => str_repeat( 'a', 64 ) ) ),
			array( 'after', array( str_repeat( 'a', 64 ), 'b' ) ),
			array( 'after', array( array( 'sha256' => str_repeat( 'a', 64 ) ) ) ),
			array( 'after', array( array( '~b' => 'AA==' ) ) ),
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

	/** The list of tables, read whole. */
	private static function whole(): array {
		return array( 'complete' => true, 'count' => 0, 'env' => 'tables', 'ref' => 'tables' );
	}

	/** One entry of a comparison of one line on each side (null: none), the lists of tables whole on both. */
	private static function one( ?array $s, ?array $l, array $more_s = array(), array $more_l = array() ): array {
		$whole   = array( array( 'complete' => true, 'count' => 0, 'env' => 'tables', 'ref' => 'tables' ) );
		$staging = array_merge( null === $s ? array() : array( $s ), $whole, $more_s );
		$live    = array_merge( null === $l ? array() : array( $l ), $whole, $more_l );
		$out     = AB_MCP_Sync_Env::compare( $staging, $live );
		self::assertCount( 1, $out['entries'], json_encode( $out['entries'] ) );
		return $out['entries'][0];
	}

	/** A plugin shop, its files by the hash $hash, its main file running or not ($active null: not read). */
	private static function plugin( string $version, $active = true, string $hash = 'a' ): array {
		return self::unit(
			'plugin',
			'shop',
			array(
				'active'  => null === $active ? null : ( $active ? array( 'main.php' ) : array() ),
				'hash'    => str_repeat( $hash, 64 ),
				'version' => $version,
			)
		);
	}

	private static function lock_of( array $entry ): array {
		return array( $entry['locks'][0]['key'], $entry['locks'][0]['stage'] );
	}

	public function testAPluginWithTheSameFilesAndMainFilesRunningIsEqualWithoutAPair(): void {
		$e = self::one( self::plugin( '2.0' ), self::plugin( '2.0' ) );
		self::assertSame( array( 'environment', 'plugin', 'shop', 'equal', 'none' ), array( $e['kind'], $e['subtype'], $e['ref'], $e['result'], $e['action'] ) );
		self::assertArrayNotHasKey( 'locks', $e, 'What is equal is not locked.' );
		self::assertArrayNotHasKey( 'contents', $e, 'Compared by contents, never «counted».' );
		self::assertSame( array( 'main.php' ), $e['staging']['active'] );
		self::assertSame( array(), array_intersect( array( 'hash', 'sha256', 'mtime' ), array_keys( $e['staging'] ) ), 'No hash in an entry.' );
		$e = self::one( self::plugin( '2.0' ), self::plugin( '1.9' ) );
		self::assertSame( 'equal', $e['result'], 'The version says nothing; the files do (Z1).' );
	}

	public function testAPluginWhoseFilesOrMainFilesRunningDifferDiffersWithThePart(): void {
		$e = self::one( self::plugin( '2.0' ), self::plugin( '2.0', true, 'b' ) );
		self::assertSame( array( 'differs', array( 'files' ), 'locked' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertSame( array( 'env:plugin', 'Z8b' ), self::lock_of( $e ) );
		$e = self::one( self::plugin( '2.0', true ), self::plugin( '2.0', false ) );
		self::assertSame( array( 'differs', array( 'activation' ) ), array( $e['result'], $e['differs'] ), 'Only which main files run (F7).' );
		self::assertStringContainsString( 'It differs in which of its main files run', $e['words'] );
		$e = self::one( self::plugin( '2.0', true ), self::plugin( '1.0', false, 'b' ) );
		self::assertSame( array( 'files', 'activation' ), $e['differs'] );
	}

	public function testAUnitOnOneSideOnly(): void {
		$e = self::one( self::plugin( '2.0' ), null );
		self::assertSame( array( 'only_staging', 'locked', null ), array( $e['result'], $e['action'], $e['live'] ) );
		self::assertSame( array( 'env:plugin', 'Z8b' ), self::lock_of( $e ) );
		$e = self::one( null, self::plugin( '2.0' ) );
		self::assertSame( array( 'only_live', 'keep', null ), array( $e['result'], $e['action'], $e['staging'] ) );
		self::assertArrayNotHasKey( 'locks', $e );
	}

	/** The pair of the plugin shop, active, with bases (E2). */
	private static function shop_pair(): array {
		$act = \AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', self::plugin( '2.0', true ) );
		return array(
			'id'           => 7,
			'kind'         => 'plugin',
			'staging_ref'  => 'shop',
			'live_ref'     => 'shop',
			'state'        => 'active',
			'canon'        => \AB_MCP_Sync_Canon::VERSION,
			'base_live'    => str_repeat( 'a', 64 ),
			'base_staging' => str_repeat( 'a', 64 ),
			'base_extra'   => array( 'parts' => array( 'activation' => array( 'live' => $act, 'staging' => $act ) ) ),
		);
	}

	/** The entries of a comparison by subtype:ref, the lists of tables whole on both sides. */
	private static function judged( array $staging, array $live, array $pairs = array() ): array {
		$out = array();
		foreach ( AB_MCP_Sync_Env::compare( array_merge( $staging, array( self::whole() ) ), array_merge( $live, array( self::whole() ) ), $pairs )['entries'] as $entry ) {
			$out[ $entry['subtype'] . ':' . $entry['ref'] ] = $entry;
		}
		return $out;
	}

	public function testAUnitOnOneSideIsOnlyThereOnlyWhereThatSideReadItWholeWithoutALock(): void {
		// Codex round 1, B3: the side that has it, read whole and not locked,
		// before «only there» or «removed».
		$unread = array( 'complete' => false, 'hash' => null, 'cause' => 'unreadable', 'files' => 0, 'bytes' => 0 ) + self::plugin( '2.0' );
		$linked = array( 'hash' => null, 'lock' => 'code:link', 'cause' => 'link', 'files' => 0, 'bytes' => 0 ) + self::plugin( '2.0' );
		$extra  = array( 'hash' => null, 'lock' => 'code:live_extra', 'cause' => 'vcs', 'files' => 0, 'bytes' => 0 ) + self::plugin( '2.0' );
		foreach ( array( 'without a pair' => array(), 'with an active pair' => array( self::shop_pair() ) ) as $case => $pairs ) {
			$e = self::judged( array( $unread ), array(), $pairs )['plugin:shop'];
			self::assertSame( array( 'not_compared', 'none', array() ), array( $e['result'], $e['action'], array_column( $e['locks'] ?? array(), 'key' ) ), "Not read whole on staging, $case." );
			self::assertSame( 'It could not be read whole on the staging site (a folder or file of it could not be read).', $e['why'] );
			$e = self::judged( array(), array( $unread ), $pairs )['plugin:shop'];
			self::assertSame( array( 'not_compared', 'none' ), array( $e['result'], $e['action'] ), "Not read whole here, $case." );
			self::assertStringContainsString( 'on this site', $e['why'] );
			$e = self::judged( array( $linked ), array(), $pairs )['plugin:shop'];
			self::assertSame( array( 'not_compared', 'locked', array( 'code:link' ) ), array( $e['result'], $e['action'], array_column( $e['locks'], 'key' ) ), "A link on staging, $case: its lock, never «only on staging» or removed." );
			$e = self::judged( array(), array( $extra ), $pairs )['plugin:shop'];
			self::assertSame( array( 'not_compared', 'locked', array( 'code:live_extra' ) ), array( $e['result'], $e['action'], array_column( $e['locks'], 'key' ) ), "A folder .git here, $case." );
		}
		$whole = self::judged( array( self::plugin( '2.0' ) ), array(), array( self::shop_pair() ) )['plugin:shop'];
		self::assertSame( array( 'deleted_live', 'none' ), array( $whole['result'], $whole['action'] ), 'Read whole without a lock: removed here, as before.' );
		$whole = self::judged( array(), array( self::plugin( '2.0' ) ) )['plugin:shop'];
		self::assertSame( array( 'only_live', 'keep' ), array( $whole['result'], $whole['action'] ) );
	}

	public function testAUnitRemovedOnOneSideAndChangedOnTheOtherIsAConflictWithTheRemoval(): void {
		// GitHub-Codex on free#147, P2: the side that has it against its own
		// bases before «removed» (TABLE, rows 3, 7, 8 and 9).
		$pair = array( self::shop_pair() );
		$e    = self::judged( array( self::plugin( '2.1', true, 'b' ) ), array(), $pair )['plugin:shop'];
		self::assertSame( array( 'deleted_live_changed_staging', 'keep', array( 'files' => 'deleted_live_changed_staging', 'activation' => 'deleted_live' ) ), array( $e['result'], $e['action'], $e['parts'] ), 'Removed here, its files changed on staging (row 9).' );
		self::assertStringContainsString( 'a conflict', $e['words'] );
		$e = self::judged( array(), array( self::plugin( '2.0', false ) ), $pair )['plugin:shop'];
		self::assertSame( array( 'deleted_against_changed_live', 'keep', array( 'files' => 'deleted_on_staging', 'activation' => 'deleted_against_changed_live' ) ), array( $e['result'], $e['action'], $e['parts'] ), 'Removed on staging, deactivated here (row 7).' );
		self::assertArrayNotHasKey( 'locks', $e );
		$e = self::judged( array(), array( self::plugin( '2.0' ) ), $pair )['plugin:shop'];
		self::assertSame( array( 'deleted_on_staging', 'locked', array( 'env:plugin' ) ), array( $e['result'], $e['action'], array_column( $e['locks'], 'key' ) ), 'Removed on staging, unchanged here (row 3).' );
		$e = self::judged( array( self::plugin( '2.0' ) ), array(), $pair )['plugin:shop'];
		self::assertSame( array( 'deleted_live', 'none' ), array( $e['result'], $e['action'] ), 'Removed here, unchanged on staging (row 8).' );
		// Each side against the base of that side: bases that differ by side.
		$apart                    = $pair;
		$apart[0]['base_live']    = str_repeat( 'c', 64 );
		$apart[0]['base_staging'] = str_repeat( 'a', 64 );
		self::assertSame( 'deleted_live', self::judged( array( self::plugin( '2.0', true, 'a' ) ), array(), $apart )['plugin:shop']['result'], 'Staging as its own base: unchanged.' );
		self::assertSame( 'deleted_on_staging', self::judged( array(), array( self::plugin( '2.0', true, 'c' ) ), $apart )['plugin:shop']['result'], 'This site as its own base: unchanged.' );
		$old            = $pair;
		$old[0]['canon'] = 'canon/0';
		self::assertStringContainsString( 'version canon/0', self::judged( array( self::plugin( '2.0' ) ), array(), $old )['plugin:shop']['why'], 'Bases of another version: not compared.' );
		$none = $pair;
		unset( $none[0]['base_extra'] );
		self::assertSame( 'Its pair holds no base of the part activation.', self::judged( array( self::plugin( '2.0' ) ), array(), $none )['plugin:shop']['why'] );
		self::assertSame( 'Which main files run could not be read on the staging site.', self::judged( array( self::plugin( '2.0', null ) ), array(), $pair )['plugin:shop']['why'] );
	}

	public function testAUnitWhoseFolderTheOtherSideDidNotListWholeIsNotCompared(): void {
		// Codex round 1, B4 (V1 of the pre-check): a folder of units a side
		// could not list says so, and a unit the other side has there is not
		// taken as only there, nor as removed.
		$root = self::unit( 'plugin', '*', array( 'form' => 'root', 'complete' => false, 'hash' => null, 'files' => 0, 'bytes' => 0, 'mains' => null, 'active' => null, 'version' => null, 'cause' => 'unreadable' ) );
		foreach ( array( 'without a pair' => array(), 'with an active pair' => array( self::shop_pair() ) ) as $case => $pairs ) {
			$all = self::judged( array( $root ), array( self::plugin( '2.0' ) ), $pairs );
			self::assertSame( array( 'not_compared', 'none' ), array( $all['plugin:shop']['result'], $all['plugin:shop']['action'] ), "The plugins of staging not listed, $case." );
			self::assertSame( 'Whether it is on the staging site is not known: the folder it would lie in could not be read whole there (a folder or file of it could not be read).', $all['plugin:shop']['why'] );
			self::assertSame( 'not_compared', $all['plugin:*']['result'], 'The folder itself says so.' );
			$all = self::judged( array( self::plugin( '2.0' ) ), array( $root ), $pairs );
			self::assertSame( array( 'not_compared', 'none' ), array( $all['plugin:shop']['result'], $all['plugin:shop']['action'] ), "The plugins here not listed, $case." );
			self::assertStringContainsString( 'on this site is not known', $all['plugin:shop']['why'] );
		}
		$moved = array( 'cause' => 'moved' ) + $root;
		self::assertStringContainsString( 'is gone since', self::judged( array( $moved ), array( self::plugin( '2.0' ) ) )['plugin:shop']['why'], 'Not listed to its end: the same.' );
		// A folder below a root of files that could not be read: the units
		// below it, and only those.
		$folder = self::unit( 'upload', '2024/05', array( 'form' => 'other', 'complete' => false, 'hash' => null, 'files' => 0, 'bytes' => 0, 'cause' => 'unreadable' ) );
		$all    = self::judged( array( $folder ), array( self::file_unit( 'upload', '2024/05/a.jpg' ), self::file_unit( 'upload', '2024/055.jpg' ), self::file_unit( 'upload', '2025/01/b.jpg' ), self::file_unit( 'upload', '2026/2024/05/c.jpg' ) ) );
		self::assertSame( 'not_compared', $all['upload:2024/05/a.jpg']['result'] );
		self::assertSame( array( 'only_live', 'only_live', 'only_live' ), array( $all['upload:2024/055.jpg']['result'], $all['upload:2025/01/b.jpg']['result'], $all['upload:2026/2024/05/c.jpg']['result'] ), 'Beside the folder, or its name further down: listed whole.' );
		self::assertSame( array( 'not_compared', 'none' ), array( $all['upload:2024/05']['result'], $all['upload:2024/05']['action'] ), 'The folder itself: not only on staging (B3).' );
	}

	public function testEveryPathOfAUnitIsInTheReportInPagesOfAHundred(): void {
		// Codex round 1, B5: past MAX_PATHS a list goes on in entries of their
		// own right after the entry, every path in its order.
		$c       = str_repeat( 'c', 64 );
		$d       = str_repeat( 'd', 64 );
		$s_files = array( array( 'main.php', 3, $c, 1 ) );
		$l_files = array( array( 'main.php', 3, $d, 1 ) );
		for ( $i = 1; $i <= 250; $i++ ) {
			$s_files[] = array( sprintf( 'new/%03d.php', $i ), 3, $c, 1 );
		}
		for ( $i = 1; $i <= 101; $i++ ) {
			$l_files[] = array( sprintf( 'data/%03d.csv', $i ), 3, $c, 1 );
		}
		usort( $s_files, static fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
		usort( $l_files, static fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
		$lines = static function ( array $line, array $files ): array {
			$line['files'] = count( $files );
			$out           = array( $line );
			foreach ( array_chunk( $files, AB_MCP_Sync_Env::PER_LINE ) as $i => $chunk ) {
				$out[] = array( 'entries' => $chunk, 'env' => 'entries', 'ref' => 'plugin:shop#' . ( $i + 1 ), 'seq' => $i + 1, 'unit' => 'plugin:shop' );
			}
			return $out;
		};
		$out = AB_MCP_Sync_Env::compare(
			array_merge( $lines( self::plugin( '2.0', true, 'a' ), $s_files ), array( self::whole() ) ),
			array_merge( $lines( self::plugin( '2.0', true, 'b' ), $l_files ), array( self::whole() ) )
		);
		$e = $out['entries'][0];
		self::assertSame( array( 'differs', 'locked' ), array( $e['result'], $e['action'] ) );
		self::assertSame( array( 250, 101, 1, 3 ), array( $e['paths']['only_staging_count'], $e['paths']['only_live_count'], $e['paths']['differs_count'], $e['paths']['more'] ) );
		self::assertSame( array( 100, 100, 1 ), array( count( $e['paths']['only_staging'] ), count( $e['paths']['only_live'] ), count( $e['paths']['differs'] ) ), 'A hundred a list in the entry.' );
		self::assertStringContainsString( 'in the entries right after this one', $e['words'] );
		self::assertCount( 4, $out['entries'], 'The entry and its three entries of further paths.' );
		$more = array_slice( $out['entries'], 1 );
		self::assertSame( array( array( 'only_live', 101, 1 ), array( 'only_staging', 101, 100 ), array( 'only_staging', 201, 50 ) ), array_map( static fn( $m ) => array( $m['paths']['list'], $m['paths']['from'], count( $m['paths']['paths'] ) ), $more ) );
		foreach ( $more as $m ) {
			self::assertSame( array( 'environment', 'plugin', 'shop', 'more_paths', 'none' ), array( $m['kind'], $m['subtype'], $m['ref'], $m['result'], $m['action'] ) );
			self::assertArrayNotHasKey( 'locks', $m );
		}
		$every = array_merge( $e['paths']['only_staging'], $more[1]['paths']['paths'], $more[2]['paths']['paths'] );
		self::assertSame( array_values( array_filter( array_column( $s_files, 0 ), static fn( $p ) => 0 === strpos( $p, 'new/' ) ) ), $every, 'Every path only on staging, in its order, none twice.' );
		self::assertSame( array_column( array_slice( $l_files, 0, 101 ), 0 ), array_merge( $e['paths']['only_live'], $more[0]['paths']['paths'] ) );
		self::assertSame( array( 'differs' => 1, 'more_paths' => 3 ), $out['counts']['results'] );
		// At the limit: no entry of further paths.
		$out = AB_MCP_Sync_Env::compare(
			array_merge( $lines( self::plugin( '2.0', true, 'a' ), array_slice( $s_files, 0, 101 ) ), array( self::whole() ) ),
			array_merge( $lines( self::plugin( '2.0', true, 'b' ), array( array( 'main.php', 3, $d, 1 ) ) ), array( self::whole() ) )
		);
		self::assertCount( 1, $out['entries'] );
		self::assertArrayNotHasKey( 'more', $out['entries'][0]['paths'] );
		self::assertSame( 100, count( $out['entries'][0]['paths']['only_staging'] ) );
	}

	public function testAUnitNotReadWholeIsNotComparedWithWhy(): void {
		$e = self::one( self::plugin( '2.0', null ), self::plugin( '2.0' ) );
		self::assertSame( 'not_compared', $e['result'] );
		self::assertStringContainsString( 'the staging site', $e['why'] );
		$e = self::one( self::plugin( '2.0' ), array( 'complete' => false, 'hash' => null, 'cause' => 'changed' ) + self::plugin( '2.0' ) );
		self::assertSame( 'not_compared', $e['result'] );
		self::assertStringContainsString( 'this site', $e['why'] );
		self::assertStringContainsString( 'changed while it was read', $e['why'] );
		$e = self::one( array( 'complete' => false, 'hash' => null, 'cause' => 'too_large' ) + self::plugin( '2.0' ), self::plugin( '2.0' ) );
		self::assertStringContainsString( 'within one call', $e['why'], 'Too large for the hash of one call (F38).' );
		$core = array( 'env' => 'core', 'ref' => 'wordpress', 'value' => null );
		self::assertSame( 'not_compared', self::one( $core, $core )['result'], 'Two unread versions are not the same version.' );
		$root = self::unit( 'language', '*', array( 'form' => 'root', 'complete' => false, 'hash' => null, 'files' => 0, 'bytes' => 0, 'cause' => 'by_code' ) );
		$e    = self::one( $root, null );
		self::assertSame( array( 'not_compared', 'none' ), array( $e['result'], $e['action'] ) );
		self::assertStringContainsString( 'code decides', $e['why'] );
	}

	public function testAUnitThatHoldsWhatNoManifestHoldsIsLockedNeverCompared(): void {
		$extra = self::unit( 'plugin', 'shop', array( 'hash' => null, 'lock' => 'code:live_extra', 'cause' => 'vcs', 'files' => 0, 'bytes' => 0 ) );
		$e     = self::one( self::plugin( '2.0' ), $extra );
		self::assertSame( array( 'not_compared', 'locked', array( 'code:live_extra', 'Z8' ) ), array( $e['result'], $e['action'], self::lock_of( $e ) ) );
		$link = array( 'lock' => 'code:link', 'cause' => 'link' ) + $extra;
		$e    = self::one( $link, self::plugin( '2.0' ) );
		self::assertSame( array( 'code:link', 'Z8' ), self::lock_of( $e ) );
		$root = self::unit( 'theme', 'elsewhere', array( 'form' => 'other', 'hash' => null, 'lock' => 'code:theme_root', 'cause' => 'theme_root' ) );
		$e    = self::one( $root, $root );
		self::assertSame( array( 'locked', array( 'code:theme_root', 'Z8' ) ), array( $e['action'], self::lock_of( $e ) ), 'A theme of a further root (E2).' );
	}

	public function testAlphaBridgeItselfIsShownByItsVersionNeverTakenOver(): void {
		$own = self::unit( 'plugin', 'alphabridge-mcp', array( 'form' => 'own', 'own' => true, 'hash' => null, 'version' => '4.19.0', 'files' => 0, 'bytes' => 0 ) );
		$e   = self::one( $own, $own );
		self::assertSame( array( 'same_value', 'none' ), array( $e['result'], $e['action'] ) );
		$e = self::one( $own, array( 'version' => '4.18.0' ) + $own );
		self::assertSame( array( 'own_differs', 'locked', array( 'code:own', 'Z8' ) ), array( $e['result'], $e['action'], self::lock_of( $e ) ) );
		self::assertStringContainsString( 'same version of AlphaBridge', $e['locks'][0]['reason'] );
		$e = self::one( $own, null );
		self::assertSame( array( 'only_staging', 'locked', array( 'code:own', 'Z8' ) ), array( $e['result'], $e['action'], self::lock_of( $e ) ), 'On staging only (Pro there, not here): never created here either.' );
		self::assertCount( 1, $e['locks'], 'No lock of a takeover beside it.' );
		// GitHub-Codex on ed1e512: a version not read is never the same.
		$unknown = self::unit( 'mu_plugin', 'alphabridge-mcp', array( 'form' => 'own', 'own' => true, 'hash' => null, 'version' => null, 'files' => 0, 'bytes' => 0 ) );
		$e       = self::one( $unknown, $unknown );
		self::assertSame( array( 'not_compared', 'locked', array( 'code:own', 'Z8' ) ), array( $e['result'], $e['action'], self::lock_of( $e ) ), 'Both versions not read: not «the same».' );
		self::assertSame( 'The version of AlphaBridge itself could not be read on the staging site: it is not compared, and never taken over.', $e['why'] );
		$known = array( 'version' => '4.19.0' ) + $unknown;
		self::assertSame( 'not_compared', self::one( $known, $unknown )['result'], 'One not read.' );
		self::assertStringContainsString( 'on this site', self::one( $known, $unknown )['why'] );
		self::assertSame( 'same_value', self::one( $known, $known )['result'], 'Both read, the same.' );
	}

	public function testAFolderPluginWhoseMainFileHasItsNameRunsByItsFullReference(): void {
		// GitHub-Codex on f25da74: a folder loader.php with its main file
		// loader.php runs as loader.php/loader.php in active_plugins; a plugin
		// of one file by its own name.
		$this->file( 'plugins/loader.php/loader.php', "<?php\n/*\n * Plugin Name: Loader\n * Version: 1.0\n */\n" );
		$this->file( 'plugins/solo.php', "<?php\n/*\n * Plugin Name: Solo\n */\n" );
		$dir   = $this->dir . '/plugins';
		$lines = new \ReflectionMethod( AB_MCP_Sync_Env::class, 'unit_lines' );
		$line  = static function ( string $name, array $active ) use ( $dir, $lines ): array {
			$read = array( 'own' => false ) + \AB_MCP_Sync_File_Manifest::unit( $dir, $name, 'live', INF, true, 'plugin' );
			return $lines->invoke( null, 'plugin', $name, $read, array( 'mains' => AB_MCP_Sync_Env::plugin_files( $dir ), 'active' => $active ) )[0];
		};
		$runs = $line( 'loader.php', array( 'solo.php', 'loader.php/loader.php' ) );
		self::assertSame( array( 'folder', array( 'loader.php' ), array( 'loader.php' ), '1.0' ), array( $runs['form'], $runs['mains'], $runs['active'], $runs['version'] ), 'Its main file runs.' );
		$off = $line( 'loader.php', array( 'loader.php' ) );
		self::assertSame( array(), $off['active'], 'loader.php alone is no file of this folder.' );
		self::assertSame( array( array( 'solo.php' ), array( 'solo.php' ) ), array( $line( 'solo.php', array( 'solo.php' ) )['active'], $line( 'solo.php', array( 'solo.php' ) )['mains'] ), 'A plugin of one file by its own name.' );
		self::assertSame( array(), $line( 'solo.php', array( 'loader.php/loader.php' ) )['active'] );
		$e = self::one( $runs, $off );
		self::assertSame( array( 'differs', array( 'activation' ) ), array( $e['result'], $e['differs'] ), 'Running on one site, not on the other: never equal.' );
	}

	public function testAFolderReadWholeWithoutAFileShowsTheFilesOfTheOtherSideAsOnlyThere(): void {
		// GitHub-Codex on f25da74: a must-use folder empty on staging, with
		// removed.php here: the report names the file, not only «differs».
		$c     = str_repeat( 'c', 64 );
		$empty = self::unit( 'mu_plugin', 'tools', array( 'files' => 0, 'bytes' => 0, 'hash' => str_repeat( 'e', 64 ) ) );
		$full  = self::unit( 'mu_plugin', 'tools', array( 'files' => 1, 'bytes' => 3, 'hash' => str_repeat( 'f', 64 ) ) );
		$of    = array( 'entries' => array( array( 'removed.php', 3, $c, 1 ) ), 'env' => 'entries', 'ref' => 'mu_plugin:tools#1', 'seq' => 1, 'unit' => 'mu_plugin:tools' );
		$e     = self::one( $empty, $full, array(), array( $of ) );
		self::assertSame( array( 'differs', array( 'files' ) ), array( $e['result'], $e['differs'] ) );
		self::assertSame(
			array(
				'differs'            => array(),
				'differs_count'      => 0,
				'only_live'          => array( 'removed.php' ),
				'only_live_count'    => 1,
				'only_staging'       => array(),
				'only_staging_count' => 0,
			),
			$e['paths'],
			'Only here, by its name and count.'
		);
		$e = self::one( $full, $empty, array( $of ) );
		self::assertSame( array( array( 'removed.php' ), 1 ), array( $e['paths']['only_staging'], $e['paths']['only_staging_count'] ), 'Only on staging, the other way round.' );
		// Only a folder read whole without a file, not locked nor held, has
		// an empty manifest; a line of entries of it after all is no manifest.
		$entries = new \ReflectionMethod( AB_MCP_Sync_Env::class, 'entries_of' );
		$files   = static function ( array $line, array $more = array() ) use ( $entries ): ?array {
			return $entries->invoke( null, array_merge( array( $line ), $more ), array( 'mu_plugin' => array( 'tools' => $line ) ) )['mu_plugin:tools'] ?? null;
		};
		self::assertSame( array(), $files( $empty ), 'Empty, read whole.' );
		foreach (
			array(
				'a file'          => array( 'form' => 'file' ),
				'not read whole'  => array( 'complete' => false ),
				'without a hash'  => array( 'hash' => null ),
				'locked'          => array( 'lock' => 'code:live_extra' ),
				'held'            => array( 'held' => 'credential' ),
				'with files'      => array( 'files' => 2 ),
			) as $why => $over
		) {
			self::assertNull( $files( array_replace( $empty, $over ) ), $why . ': no manifest.' );
		}
		self::assertNull( $files( $empty, array( $of ) ), 'A line of entries of it after all: no manifest (fail closed).' );
	}

	public function testThePathsOfAUnitWhoseFilesDifferAreShownNeverByMtime(): void {
		$s = self::plugin( '2.0', true, 'a' );
		$l = self::plugin( '2.0', true, 'b' );
		$entries = static function ( string $side, array $list ): array {
			return array( 'entries' => $list, 'env' => 'entries', 'ref' => 'plugin:shop#1', 'seq' => 1, 'unit' => 'plugin:shop' );
		};
		$c = str_repeat( 'c', 64 );
		$d = str_repeat( 'd', 64 );
		$s['files'] = 3;
		$l['files'] = 3;
		$out = AB_MCP_Sync_Env::compare(
			array( $s, $entries( 's', array( array( 'main.php', 3, $c, 1 ), array( 'new.php', 3, $c, 1 ), array( 'same.php', 3, $c, 1 ) ) ), self::whole() ),
			array( $l, $entries( 'l', array( array( 'data/export.csv', 3, $c, 9 ), array( 'main.php', 3, $d, 1 ), array( 'same.php', 3, $c, 9 ) ) ), self::whole() )
		);
		self::assertCount( 1, $out['entries'], 'The entries of a unit are no entry of their own.' );
		$e = $out['entries'][0];
		self::assertSame(
			array(
				'differs'            => array( 'main.php' ),
				'differs_count'      => 1,
				'only_live'          => array( 'data/export.csv' ),
				'only_live_count'    => 1,
				'only_staging'       => array( 'new.php' ),
				'only_staging_count' => 1,
			),
			$e['paths'],
			'same.php with another mtime is the same file.'
		);
		self::assertStringContainsString( 'into the backup', $e['words'], 'A path not in the new version (F47).' );
		$l['files'] = 4;
		$out        = AB_MCP_Sync_Env::compare(
			array( $s, $entries( 's', array( array( 'main.php', 3, $c, 1 ), array( 'new.php', 3, $c, 1 ), array( 'same.php', 3, $c, 1 ) ) ), self::whole() ),
			array( $l, $entries( 'l', array( array( 'data/export.csv', 3, $c, 9 ), array( 'main.php', 3, $d, 1 ), array( 'same.php', 3, $c, 9 ) ) ), self::whole() )
		);
		self::assertArrayNotHasKey( 'paths', $out['entries'][0], 'Not every file of a side at hand: no paths (fail closed).' );
	}

	public function testThePartsOfAUnitWithAPairAreJudgedEachAgainstItsOwnBase(): void {
		$base  = str_repeat( 'a', 64 );
		$act   = \AB_MCP_Sync_Decision::part_hash( 'plugin', 'activation', self::plugin( '2.0', true ) );
		$pair  = array(
			'id'           => 7,
			'kind'         => 'plugin',
			'staging_ref'  => 'shop',
			'live_ref'     => 'shop',
			'state'        => 'active',
			'canon'        => \AB_MCP_Sync_Canon::VERSION,
			'base_live'    => $base,
			'base_staging' => $base,
			'base_extra'   => array( 'parts' => array( 'activation' => array( 'live' => $act, 'staging' => $act ) ) ),
		);
		$judge = static function ( array $s, array $l ) use ( $pair ): array {
			return AB_MCP_Sync_Env::compare( array( $s, self::whole() ), array( $l, self::whole() ), array( $pair ) )['entries'][0];
		};
		$e = $judge( self::plugin( '2.0' ), self::plugin( '2.0' ) );
		self::assertSame( array( 'equal', array( 'files' => 'equal', 'activation' => 'equal' ), 7 ), array( $e['result'], $e['parts'], $e['pair']['id'] ) );
		$e = $judge( self::plugin( '2.1', true, 'b' ), self::plugin( '2.0' ) );
		self::assertSame( array( 'changed_on_staging', 'locked', array( 'env:plugin', 'Z8b' ) ), array( $e['result'], $e['action'], self::lock_of( $e ) ) );
		$e = $judge( self::plugin( '2.0' ), self::plugin( '2.0', true, 'b' ) );
		self::assertSame( array( 'changed_live', 'keep' ), array( $e['result'], $e['action'] ) );
		$e = $judge( self::plugin( '2.1', true, 'b' ), self::plugin( '2.0', true, 'c' ) );
		self::assertSame( array( 'conflict', 'locked' ), array( $e['result'], $e['action'] ) );
		self::assertSame( array( 'code:live_changed', 'env:plugin' ), array_column( $e['locks'], 'key' ), 'Changed on both sides: no choice «staging wins» (E1).' );
		self::assertStringContainsString( 'update it by hand in wp-admin', $e['locks'][0]['reason'], 'The way for a plugin that keeps writing into its folder (F46).' );
		self::assertArrayNotHasKey( 'choices', $e );
		$e = $judge( self::plugin( '2.1', true, 'b' ), self::plugin( '2.0', false ) );
		self::assertSame( array( 'files' => 'changed_on_staging', 'activation' => 'changed_live' ), $e['parts'], 'Each part against its own base (E2, F51).' );
		self::assertSame( array( 'code:live_changed' ), array_values( array_diff( array( 'code:live_changed' ), array_column( $e['locks'], 'key' ) ) ), 'No conflict of the files: no code:live_changed.' );
		$e = $judge( self::plugin( '2.0', false ), self::plugin( '2.0', false ) );
		self::assertSame( array( 'activation' => 'changed_alike' ), array_intersect_key( $e['parts'], array( 'activation' => 1 ) ) );
		$unclear = $judge( self::plugin( '2.0' ), self::plugin( '2.0' ) );
		$pair['state'] = 'unclear';
		$e = AB_MCP_Sync_Env::compare( array( self::plugin( '2.0' ), self::whole() ), array( self::plugin( '2.0' ), self::whole() ), array( $pair ) )['entries'][0];
		self::assertSame( 'not_compared', $e['result'] );
		self::assertStringContainsString( 'wp_site_pairs', $e['why'] );
		self::assertSame( 'equal', $unclear['result'] );
	}

	public function testTheThemeThatRunsIsAUnitOfItsOwn(): void {
		$choice = array( 'env' => 'theme_choice', 'ref' => 'theme_choice', 'stylesheet' => 'child', 'template' => 'parent' );
		$e      = self::one( $choice, $choice );
		self::assertSame( array( 'theme_choice', 'equal', 'none' ), array( $e['subtype'], $e['result'], $e['action'] ) );
		$e = self::one( $choice, array( 'stylesheet' => 'other' ) + $choice );
		self::assertSame( array( 'differs', array( 'activation' ), 'locked', array( 'env:theme_choice', 'Z8b' ) ), array( $e['result'], $e['differs'], $e['action'], self::lock_of( $e ) ) );
		$e = self::one( $choice, array( 'template' => null ) + $choice );
		self::assertSame( 'not_compared', $e['result'] );
	}

	public function testWordPressAndTheLanguageAreShownOnlyAndTheCoreFilesComparedByTheirChecksums(): void {
		$wp = array( 'env' => 'core', 'ref' => 'wordpress', 'value' => '7.1.3' );
		$e  = self::one( $wp, $wp );
		self::assertSame( array( 'same_value', 'host' ), array( $e['result'], $e['action'] ) );
		self::assertArrayNotHasKey( 'locks', $e, 'Never taken over (A17): changed at the host.' );
		self::assertStringContainsString( 'wp_update_core', $e['words'] );
		$e = self::one( $wp, array( 'value' => '7.2' ) + $wp );
		self::assertSame( array( 'differs', array( 'value' ) ), array( $e['result'], $e['differs'] ) );
		$locale = array( 'env' => 'core', 'ref' => 'locale', 'value' => 'de_CH' );
		$e      = self::one( $locale, $locale );
		self::assertSame( array( 'same_value', 'none', 'de_CH', 'de_CH' ), array( $e['result'], $e['action'], $e['staging']['value'], $e['live']['value'] ), 'Both values shown, never equal.' );
		self::assertStringContainsString( 'Shown only', $e['words'] );
		$e = self::one( $locale, array( 'value' => 'en_US' ) + $locale );
		self::assertSame( array( 'differs', 'none' ), array( $e['result'], $e['action'] ), 'The option WPLANG is compared with the options: here shown only.' );
		self::assertArrayNotHasKey( 'locks', $e );
		$core = self::lines()['core_files'];
		$e    = self::one( $core, $core );
		self::assertSame( array( 'equal', 'host' ), array( $e['result'], $e['action'] ), 'Both as shipped (E18).' );
		$e = self::one( $core, array( 'differs' => 1 ) + $core );
		self::assertSame( array( 'differs', array( 'differs' ), 'host', 1 ), array( $e['result'], $e['differs'], $e['action'], $e['live']['differs'] ), 'One core file changed here (F17).' );
		self::assertStringContainsString( 're-install', strtolower( $e['words'] ) );
		$e = self::one( array( 'missing' => 2 ) + $core, $core );
		self::assertSame( array( 'differs', array( 'missing' ) ), array( $e['result'], $e['differs'] ) );
		$e = self::one( $core, array( 'complete' => false, 'checked' => 0, 'cause' => 'unreachable' ) + $core );
		self::assertSame( array( 'not_compared', 'host' ), array( $e['result'], $e['action'] ) );
		self::assertStringContainsString( 'api.wordpress.org was not reached', $e['why'] );
	}

	public function testAMustUseFileIsEqualOrDiffersByItsContents(): void {
		$mu = self::file_unit( 'mu_plugin', 'site.php', 'a' );
		$e  = self::one( $mu, $mu );
		self::assertSame( array( 'equal', 'none' ), array( $e['result'], $e['action'] ) );
		self::assertArrayNotHasKey( 'locks', $e );
		$e = self::one( $mu, self::file_unit( 'mu_plugin', 'site.php', 'b' ) );
		self::assertSame( array( 'differs', array( 'files' ), 'locked' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertSame( array( 'env:mu_plugin', 'Z8b' ), self::lock_of( $e ) );
		self::assertSame( 3, $e['staging']['bytes'] );
		$e = self::one( $mu, null );
		self::assertSame( array( 'only_staging', 'locked' ), array( $e['result'], $e['action'] ) );
	}

	public function testADropInIsEqualOrDiffersAndIsChangedAtTheHost(): void {
		$d = array( 'bytes' => 10, 'env' => 'dropin', 'ref' => 'object-cache.php', 'sha256' => str_repeat( 'a', 64 ) );
		$e = self::one( $d, $d );
		self::assertSame( array( 'equal', 'host' ), array( $e['result'], $e['action'] ), 'Equal by its SHA-256, and of group U: changed at the host.' );
		self::assertStringContainsString( 'Change at the host', $e['words'] );
		$e = self::one( $d, array( 'sha256' => str_repeat( 'b', 64 ) ) + $d );
		self::assertSame( array( 'differs', array( 'contents' ), 'host' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertStringContainsString( 'Change at the host', $e['words'] );
		self::assertArrayNotHasKey( 'locks', $e );
		$e = self::one( null, $d );
		self::assertSame( array( 'only_live', 'host' ), array( $e['result'], $e['action'] ) );
	}

	public function testTheValuesOfTheHostAreShownNeverEqualAndChangedAtTheHost(): void {
		$php = array( 'defined' => true, 'env' => 'host', 'ref' => 'php', 'value' => '8.3.1' );
		$e   = self::one( $php, $php );
		self::assertSame( array( 'same_value', 'host', '8.3.1', '8.3.1' ), array( $e['result'], $e['action'], $e['staging']['value'], $e['live']['value'] ), 'The same version on both sides: both shown, never equal.' );
		self::assertStringContainsString( 'not compared', $e['words'] );
		self::assertStringContainsString( 'Change at the host', $e['words'] );
		self::assertArrayNotHasKey( 'differs', $e );
		$e = self::one( $php, array( 'value' => '8.2.0' ) + $php );
		self::assertSame( array( 'differs', array( 'value' ), 'host' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertSame( '8.3.1', $e['staging']['value'], 'A value of the host is shown.' );
		$debug = array( 'defined' => true, 'env' => 'host', 'ref' => 'constant:WP_DEBUG', 'value' => true );
		$none  = array( 'defined' => false, 'value' => null ) + $debug;
		$e     = self::one( $debug, $none );
		self::assertSame( array( 'differs', array( 'defined' ), 'host' ), array( $e['result'], $e['differs'], $e['action'] ) );
		self::assertSame( array( 'same_value', 'host' ), array_values( array_intersect_key( self::one( $none, $none ), array_flip( array( 'result', 'action' ) ) ) ), 'Not defined on both sides.' );
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
		self::assertSame( array( 'same_value', 'not shown', 'host' ), array( $e['result'], $e['staging']['value'], $e['action'] ) );
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
		$f = array( 'attached' => 0, 'bytes' => 10, 'complete' => true, 'env' => 'files', 'files' => 2, 'ref' => 'uploads', 'skipped' => 0, 'why' => null );
		$e = self::one( $f, $f );
		self::assertSame( array( 'counted', 'locked', 'Z8b' ), array( $e['result'], $e['action'], $e['stage'] ) );
		self::assertSame( array( 'env:uploads', 'Z8b' ), self::lock_of( $e ) );
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
			'plugin'        => self::plugin( '1' ),
			'theme'         => self::unit( 'theme', 't' ),
			'theme_choice'  => array( 'env' => 'theme_choice', 'ref' => 'theme_choice', 'stylesheet' => 'a', 'template' => 'a' ),
			'mu_plugin'     => self::file_unit( 'mu_plugin', 'a.php' ),
			'language'      => self::file_unit( 'language', 'de_CH.mo' ),
			'font'          => self::file_unit( 'font', 'a.woff2' ),
			'upload'        => self::file_unit( 'upload', '2026/11/a.pdf' ),
			'table'         => array( 'env' => 'table', 'ref' => 'x', 'rows' => 1, 'schema' => str_repeat( 'a', 64 ) ),
			'files:uploads' => array( 'attached' => 0, 'bytes' => 1, 'complete' => true, 'env' => 'files', 'files' => 1, 'ref' => 'uploads', 'skipped' => 0, 'why' => null ),
		);
		self::assertSame( array_keys( AB_MCP_Sync_Env::LOCKS ), array_keys( $cases ), 'A case per lock.' );
		$tables = array( array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' ) );
		$want   = array(
			'plugin'        => array( 'env:plugin', 'A11', 'Z8b' ),
			'theme'         => array( 'env:theme', 'A11', 'Z8b' ),
			'theme_choice'  => array( 'env:theme_choice', 'A9', 'Z8b' ),
			'mu_plugin'     => array( 'env:mu_plugin', 'A21', 'Z8b' ),
			'language'      => array( 'env:languages', 'A21', 'Z8b' ),
			'font'          => array( 'env:fonts', 'A20', 'Z8b' ),
			'upload'        => array( 'env:upload_files', 'A22', 'Z8b' ),
			'table'         => array( 'env:table', 'A16', 'Z8d' ),
			'files:uploads' => array( 'env:uploads', 'A22', 'Z8b' ),
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
		self::assertSame( array( 'code:live_extra', 'code:link', 'code:theme_root', 'code:own', 'code:live_changed' ), array_keys( AB_MCP_Sync_Env::CODE_LOCKS ) );
		foreach ( AB_MCP_Sync_Env::CODE_LOCKS as $key => $lock ) {
			self::assertSame( 'Z8', $lock['stage'], $key . ': no stage lifts it, a person resolves it.' );
			self::assertStringContainsString( 'new report', $lock['reason'] . ( 'code:theme_root' === $key ? ' new report' : '' ), $key . ': with its way.' );
		}
	}

	public function testNothingCountedIsEverEqualAndNothingEqualIsLocked(): void {
		$tables = array( 'complete' => true, 'count' => 1, 'env' => 'tables', 'ref' => 'tables' );
		$lines  = array_values( array_filter( self::lines(), static fn( $l ) => 'page' !== $l['env'] && 'tables' !== $l['env'] && ( 'files' !== $l['env'] || $l['complete'] ) ) );
		$out    = AB_MCP_Sync_Env::compare( array_merge( $lines, array( $tables ) ), array_merge( $lines, array( $tables ) ) );
		foreach ( $out['entries'] as $entry ) {
			if ( in_array( $entry['subtype'], array( 'table', 'files', 'host', 'core' ), true ) ) {
				self::assertNotSame( 'equal', $entry['result'], $entry['subtype'] . ' ' . $entry['ref'] . ': counted or shown, never equal.' );
			}
			if ( 'equal' === $entry['result'] ) {
				self::assertContains( $entry['subtype'], array( 'plugin', 'theme', 'mu_plugin', 'language', 'font', 'upload', 'theme_choice', 'dropin', 'core_files' ), 'Equal only by contents.' );
				self::assertArrayNotHasKey( 'locks', $entry );
			}
			if ( in_array( $entry['subtype'], array( 'host', 'dropin', 'core_files' ), true ) ) {
				self::assertSame( 'host', $entry['action'], $entry['ref'] . ': changed at the host.' );
			}
		}
		self::assertStringNotContainsString( '"sha256"', (string) json_encode( $out ), 'No hash in an entry.' );
		self::assertStringNotContainsString( '"hash"', (string) json_encode( $out ) );
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
			array( 'env' => 'page', 'files' => 'ab-files/1', 'last' => true, 'ref' => 'page', 'seq' => 1, 'walk' => str_repeat( 'a', 32 ) ),
		);
		$out = AB_MCP_Sync_Env::compare( $lines, array( $tables ) );
		self::assertSame( array( 'core:wordpress', 'plugin:shop', 'table:A', 'table:b' ), array_map( static fn( $e ) => $e['subtype'] . ':' . $e['ref'], $out['entries'] ), 'No page, no list of tables.' );
		self::assertSame( array( 'entries' => 4, 'results' => array( 'only_staging' => 4 ), 'actions' => array( 'host' => 1, 'locked' => 3 ) ), $out['counts'] );
	}

	public function testTheLoaderAndTheDropInsAreThoseOfWordPressAndThisPlugin(): void {
		self::assertSame( \AB_MCP_Preview_Protection::LOADER_FILE, AB_MCP_Sync_Env::LOADER );
		self::assertSame( array( 'advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'object-cache.php', 'php-error.php', 'fatal-error-handler.php' ), AB_MCP_Sync_Env::DROPINS, 'The integration suite holds them against _get_dropins().' );
		$this->file( 'site.php', '<?php' );
		$this->file( 'sub/inner.php', '<?php' );
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
