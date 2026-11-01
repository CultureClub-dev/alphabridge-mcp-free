<?php
/**
 * The tables of the takeover (AB_MCP_Sync_Schema, Z8 decision 3), as far as
 * a test without a database shows them:
 *
 * - the statements are what dbDelta() reads (two spaces after PRIMARY KEY,
 *   KEY rather than INDEX, no backticks, one column per line, indexed text
 *   columns of at most 191 characters, the charset of the site);
 * - on a network nothing is made and nothing is asked: install() refuses,
 *   maybe_upgrade() does nothing, status() says multisite, and every
 *   method of the pairs refuses before any query;
 * - the version is stored only when the tables are there afterwards; a
 *   failed attempt is marked and tried again on plugins_loaded only after
 *   RETRY, on activation at once;
 * - the plugin file hooks maybe_upgrade() on plugins_loaded and activation
 *   calls install().
 *
 * That dbDelta() makes them on MariaDB, that a second run changes nothing,
 * that the unique keys hold and that uninstall drops them is
 * tests/integration/SyncSchemaTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Schema;
use AbTestSyncWpdb;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-schema.php';
require_once __DIR__ . '/../includes/sync/class-sync-pairs.php';

final class SyncSchemaTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['wpdb']            = new AbTestSyncWpdb();
		$GLOBALS['ab_test_dbdelta'] = array();
		AB_MCP_Sync_Schema::forget();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['ab_test_dbdelta'] );
		AB_MCP_Sync_Schema::forget();
	}

	private static function db(): AbTestSyncWpdb {
		return $GLOBALS['wpdb'];
	}

	public function testTheStatementsAreWhatDbDeltaReads(): void {
		$sql = AB_MCP_Sync_Schema::sql();
		self::assertCount( 3, $sql );
		foreach ( array_values( AB_MCP_Sync_Schema::TABLES ) as $i => $table ) {
			$statement = $sql[ $i ];
			self::assertStringStartsWith( 'CREATE TABLE wp_' . $table . " (\n", $statement, 'dbDelta() takes the name up to the first space.' );
			self::assertStringEndsWith( "\n) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;", $statement, 'The charset of the site.' );
			self::assertStringNotContainsString( '`', $statement, 'No backticks.' );
			self::assertDoesNotMatchRegularExpression( '/\bINDEX\b/', $statement, 'KEY, not INDEX.' );
			self::assertMatchesRegularExpression( '/^PRIMARY KEY {2}\(/m', $statement, 'Two spaces after PRIMARY KEY.' );

			$short = (string) array_search( $table, AB_MCP_Sync_Schema::TABLES, true );
			$lines = explode( "\n", substr( $statement, strpos( $statement, "(\n" ) + 2, strrpos( $statement, "\n)" ) - strpos( $statement, "(\n" ) - 2 ) );
			$names = array();
			$types = array();
			foreach ( $lines as $line ) {
				$line = rtrim( $line, ',' );
				if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY) /', $line ) ) {
					preg_match( '/\(([^)]*)\)$/', $line, $m );
					self::assertStringNotContainsString( ' ', $m[1], 'The columns of a key without spaces: ' . $line );
					foreach ( explode( ',', $m[1] ) as $column ) {
						self::assertArrayHasKey( $column, $types, 'A key names a column of the table: ' . $line );
						if ( preg_match( '/^(?:var)?char\((\d+)\)/', $types[ $column ], $len ) ) {
							self::assertLessThanOrEqual( 191, (int) $len[1], 'An indexed text column holds at most 191 characters (767 bytes in utf8mb4): ' . $column );
						}
					}
					continue;
				}
				self::assertMatchesRegularExpression( '/^[a-z_]+ [a-z]+(\(\d+\))?( unsigned)? (NOT NULL|NULL)/', $line, 'One column per line, as DESCRIBE names its type: ' . $line );
				$name           = strtok( $line, ' ' );
				$names[]        = $name;
				$types[ $name ] = substr( $line, strlen( $name ) + 1 );
			}
			self::assertSame( array_keys( AB_MCP_Sync_Schema::COLUMNS[ $short ] ), $names );
			self::assertNotContains( 'before', $names, 'BEFORE is a reserved word of MySQL: the old state of a pair is pair_before.' );
		}
	}

	public function testOnANetworkNothingIsMadeAndNothingAsked(): void {
		$GLOBALS['ab_test_multisite'] = true;

		AB_MCP_Sync_Schema::maybe_upgrade();
		$made = AB_MCP_Sync_Schema::install();

		self::assertInstanceOf( WP_Error::class, $made );
		self::assertSame( 'ab_mcp_staging_multisite', $made->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_dbdelta'], 'dbDelta() never ran.' );
		self::assertFalse( get_option( AB_MCP_Sync_Schema::OPTION ), 'No version stored.' );
		self::assertSame( array( 'available' => false, 'reason' => 'multisite' ), AB_MCP_Sync_Schema::status() );
		self::assertFalse( AB_MCP_Sync_Schema::available() );

		// The pairs refuse, each before any query, with the reason.
		$hash  = str_repeat( 'a', 64 );
		$calls = array(
			'get'           => static fn() => AB_MCP_Sync_Pairs::get( 1 ),
			'find_live'     => static fn() => AB_MCP_Sync_Pairs::find_live( 'src', 'post', '5' ),
			'find_staging'  => static fn() => AB_MCP_Sync_Pairs::find_staging( 'src', 'post', '5' ),
			'page'          => static fn() => AB_MCP_Sync_Pairs::page( 'src', 'post' ),
			'create'        => static fn() => AB_MCP_Sync_Pairs::create( array() ),
			'set_state'     => static fn() => AB_MCP_Sync_Pairs::set_state( 1, 'unclear', $hash ),
			'rebase'        => static fn() => AB_MCP_Sync_Pairs::rebase( 1, array(), $hash ),
			'realign'       => static fn() => AB_MCP_Sync_Pairs::realign( 1, array(), $hash, true ),
			'discard'       => static fn() => AB_MCP_Sync_Pairs::discard( 1, $hash ),
			'snapshot'      => static fn() => AB_MCP_Sync_Pairs::snapshot( 1 ),
			'restore'       => static fn() => AB_MCP_Sync_Pairs::restore( 1, '{}', $hash ),
			'change_canon'  => static fn() => AB_MCP_Sync_Pairs::change_canon( 1, 'canon/2', static fn() => array() ),
		);
		foreach ( $calls as $name => $call ) {
			$answer = $call();
			self::assertInstanceOf( WP_Error::class, $answer, $name );
			self::assertSame( 'ab_mcp_staging_unavailable', $answer->get_error_code(), $name );
			self::assertSame( array( 'reason' => 'multisite' ), $answer->get_error_data(), $name );
			self::assertStringContainsString( 'multisite', $answer->get_error_message(), $name );
		}
		self::assertSame( array(), self::db()->queries, 'Not one query on a network.' );
	}

	public function testTheVersionIsStoredOnlyWhenTheTablesAreThereAfterwards(): void {
		// dbDelta() ran, and the tables are still not there (a host that
		// refuses them): the attempt is marked, not the version.
		$now  = time();
		$made = AB_MCP_Sync_Schema::install();
		self::assertInstanceOf( WP_Error::class, $made );
		self::assertSame( 'ab_mcp_staging_tables_failed', $made->get_error_code() );
		self::assertCount( 1, $GLOBALS['ab_test_dbdelta'] );
		self::assertSame( AB_MCP_Sync_Schema::sql(), $GLOBALS['ab_test_dbdelta'][0] );
		self::assertContains( 'table wp_ab_mcp_staging_pairs missing', $made->get_error_data()['problems'] );
		self::assertMatchesRegularExpression( '/^failed:1:(\d+)\z/', (string) get_option( AB_MCP_Sync_Schema::OPTION ) );
		self::assertGreaterThanOrEqual( $now, (int) substr( (string) get_option( AB_MCP_Sync_Schema::OPTION ), 9 ) );
		self::assertSame( array( 'available' => false, 'reason' => 'failed' ), AB_MCP_Sync_Schema::status() );

		// Now dbDelta() makes them: the version is stored, and they count.
		self::db()->after_dbdelta = AbTestSyncWpdb::all_tables();
		self::assertSame( array(), AB_MCP_Sync_Schema::install() );
		self::assertSame( '1', get_option( AB_MCP_Sync_Schema::OPTION ) );
		self::assertSame( array( 'available' => true, 'reason' => null ), AB_MCP_Sync_Schema::status() );
	}

	public function testAFailedAttemptIsMadeAgainOnPluginsLoadedOnlyAfterTheWait(): void {
		update_option( AB_MCP_Sync_Schema::OPTION, 'failed:1:' . ( time() - 10 ) );
		AB_MCP_Sync_Schema::maybe_upgrade();
		self::assertSame( array(), $GLOBALS['ab_test_dbdelta'], 'Not again within RETRY: dbDelta() is not run on every request of a host that refuses the tables.' );

		update_option( AB_MCP_Sync_Schema::OPTION, 'failed:1:' . ( time() - AB_MCP_Sync_Schema::RETRY - 1 ) );
		AB_MCP_Sync_Schema::maybe_upgrade();
		self::assertCount( 1, $GLOBALS['ab_test_dbdelta'], 'After RETRY it is tried again.' );

		// A failure of another version does not hold this one back.
		update_option( AB_MCP_Sync_Schema::OPTION, 'failed:0:' . time() );
		AB_MCP_Sync_Schema::maybe_upgrade();
		self::assertCount( 2, $GLOBALS['ab_test_dbdelta'] );

		// The activation tries at once.
		update_option( AB_MCP_Sync_Schema::OPTION, 'failed:1:' . time() );
		AB_MCP_Sync_Schema::install();
		self::assertCount( 3, $GLOBALS['ab_test_dbdelta'] );
	}

	public function testPluginsLoadedMakesTheTablesWhenTheVersionIsAnotherAndOnlyThen(): void {
		self::db()->after_dbdelta = AbTestSyncWpdb::all_tables();
		foreach ( array( '' => 1, '0' => 1, '2' => 1, '1' => 0 ) as $stored => $runs ) {
			$GLOBALS['ab_test_dbdelta'] = array();
			if ( '' === (string) $stored ) {
				delete_option( AB_MCP_Sync_Schema::OPTION );
			} else {
				update_option( AB_MCP_Sync_Schema::OPTION, (string) $stored );
			}
			AB_MCP_Sync_Schema::maybe_upgrade();
			self::assertCount( $runs, $GLOBALS['ab_test_dbdelta'], 'stored version "' . $stored . '"' );
			self::assertSame( '1', get_option( AB_MCP_Sync_Schema::OPTION ) );
		}
	}

	public function testStatusNamesWhyTheTablesCannotBeUsed(): void {
		$cases = array(
			array( null, false, 'not_installed' ),
			array( 'failed:1:' . time(), false, 'failed' ),
			array( '2', true, 'outdated' ),
			array( '1', false, 'missing' ),
			array( '1', true, null ),
		);
		foreach ( $cases as list( $stored, $tables, $reason ) ) {
			AB_MCP_Sync_Schema::forget();
			if ( null === $stored ) {
				delete_option( AB_MCP_Sync_Schema::OPTION );
			} else {
				update_option( AB_MCP_Sync_Schema::OPTION, $stored );
			}
			self::db()->tables = $tables ? AbTestSyncWpdb::all_tables() : array();
			self::assertSame( array( 'available' => null === $reason, 'reason' => $reason ), AB_MCP_Sync_Schema::status(), (string) $stored );
		}

		// One table, one column or one unique key missing is «missing».
		AB_MCP_Sync_Schema::forget();
		$tables = AbTestSyncWpdb::all_tables();
		unset( $tables['wp_ab_mcp_staging_steps'] );
		self::db()->tables = $tables;
		self::assertSame( 'missing', AB_MCP_Sync_Schema::status()['reason'] );
		self::assertSame( array( 'table wp_ab_mcp_staging_steps missing' ), AB_MCP_Sync_Schema::check() );
	}

	public function testThePluginHooksTheTablesOnActivationAndOnPluginsLoaded(): void {
		$main = (string) file_get_contents( dirname( __DIR__ ) . '/alphabridge-mcp.php' );
		foreach ( array( 'class-sync-schema.php', 'class-sync-pairs.php', 'class-sync-kinds.php' ) as $file ) {
			self::assertStringContainsString( "require_once AB_MCP_DIR . 'includes/sync/$file';", $main );
		}
		self::assertStringContainsString( "add_action( 'plugins_loaded', array( 'AB_MCP_Sync_Schema', 'maybe_upgrade' ), 10, 0 );", $main );
		$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );
		self::assertMatchesRegularExpression( '/public static function on_activate\([^)]*\) \{[^}]*AB_MCP_Sync_Schema::install\(\);/s', $plugin );
	}
}
