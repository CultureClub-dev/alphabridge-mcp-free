<?php
/**
 * The tables of the takeover on MariaDB (AB_MCP_Sync_Schema, Z8 decision 3):
 *
 * - dbDelta() makes the three tables with every column and key; a second
 *   run changes nothing, and plugins_loaded with the version stored asks
 *   nothing;
 * - a request to a site without the tables makes them on plugins_loaded;
 * - the unique keys refuse a second pair on (source_id, kind, live_ref)
 *   and on (source_id, kind, staging_ref) in the table itself;
 * - a table or key that is missing is named, and the pairs refuse;
 * - a host that refuses a key gets the attempt marked, and plugins_loaded
 *   tries again only after RETRY;
 * - uninstalling drops the tables and the option of that site only.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Schema;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class SyncSchemaTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_Sync_Schema::uninstall();
		AB_MCP_Sync_Schema::forget();
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		AB_MCP_Sync_Schema::forget();
	}

	/** @return string[] The tables of the takeover on a site. */
	private static function tables( string $key ): array {
		global $wpdb;
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( AB_MCP_IT_Sites::prefix( $key ) . 'ab_mcp_staging_' ) . '%' ) );
		sort( $found );
		return $found;
	}

	/** @return string[] SHOW CREATE TABLE of each table of site A. */
	private static function created(): array {
		global $wpdb;
		$out = array();
		foreach ( AB_MCP_Sync_Schema::TABLES as $table ) {
			$out[ $table ] = (string) $wpdb->get_row( 'SHOW CREATE TABLE `' . AB_MCP_IT_Sites::table( 'a', $table ) . '`', ARRAY_N )[1];
		}
		return $out;
	}

	/** Every query of $wpdb while $run runs. */
	private static function queries( callable $run ): array {
		$seen   = array();
		$filter = static function ( $query ) use ( &$seen ) {
			$seen[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$run();
		} finally {
			remove_filter( 'query', $filter );
		}
		return $seen;
	}

	private static function names(): array {
		$names = array();
		foreach ( AB_MCP_Sync_Schema::TABLES as $table ) {
			$names[] = AB_MCP_IT_Sites::table( 'a', $table );
		}
		sort( $names );
		return $names;
	}

	public function testDbDeltaMakesTheTablesAndASecondRunChangesNothing(): void {
		self::assertSame( array(), self::tables( 'a' ) );
		self::assertNull( AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Sync_Schema::OPTION ) );

		$first = AB_MCP_Sync_Schema::install();
		self::assertIsArray( $first );
		foreach ( self::names() as $name ) {
			self::assertContains( 'Created table ' . $name, $first );
		}
		self::assertSame( self::names(), self::tables( 'a' ) );
		self::assertSame( array(), AB_MCP_Sync_Schema::check() );
		self::assertSame( '1', AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Sync_Schema::OPTION )['option_value'] );
		self::assertTrue( AB_MCP_Sync_Schema::available() );

		global $wpdb;
		foreach ( AB_MCP_Sync_Schema::TABLES as $short => $table ) {
			$columns = $wpdb->get_col( 'SHOW COLUMNS FROM `' . AB_MCP_IT_Sites::table( 'a', $table ) . '`' );
			self::assertSame( array_keys( AB_MCP_Sync_Schema::COLUMNS[ $short ] ), $columns, $table );
		}
		$created = self::created();
		self::assertStringContainsString( 'UNIQUE KEY `live_ref` (`source_id`,`kind`,`live_ref`)', $created['ab_mcp_staging_pairs'] );
		self::assertStringContainsString( 'UNIQUE KEY `staging_ref` (`source_id`,`kind`,`staging_ref`)', $created['ab_mcp_staging_pairs'] );
		self::assertStringContainsString( 'PRIMARY KEY (`run_id`,`seq`)', $created['ab_mcp_staging_steps'] );
		self::assertStringContainsString( 'utf8mb4', $created['ab_mcp_staging_pairs'] );

		// The second run: dbDelta() finds nothing to change, and the tables are byte for byte the same.
		self::assertSame( array(), AB_MCP_Sync_Schema::install() );
		self::assertSame( $created, self::created() );

		// plugins_loaded with the version stored asks the database nothing.
		$asked = self::queries( array( AB_MCP_Sync_Schema::class, 'maybe_upgrade' ) );
		self::assertSame( array(), preg_grep( '/^(CREATE|ALTER|DESCRIBE|SHOW)/i', $asked ) );
	}

	public function testARequestToASiteWithoutTheTablesMakesThemOnPluginsLoaded(): void {
		global $wpdb;
		AB_MCP_IT_Sites::reset( 'b' );
		foreach ( AB_MCP_Sync_Schema::TABLES as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . AB_MCP_IT_Sites::table( 'b', $table ) . '`' );
		}
		$wpdb->delete( AB_MCP_IT_Sites::table( 'b', 'options' ), array( 'option_name' => AB_MCP_Sync_Schema::OPTION ) );
		self::assertSame( array(), self::tables( 'b' ) );

		$answer = AB_MCP_IT_Sites::request( 'b', 'GET', '/' );
		self::assertSame( 200, $answer['status'], $answer['raw'] );

		self::assertCount( 3, self::tables( 'b' ) );
		self::assertSame( '1', AB_MCP_IT_Sites::option_row( 'b', AB_MCP_Sync_Schema::OPTION )['option_value'] );
		self::assertSame( array(), self::tables( 'a' ), 'Only the site that answered.' );
	}

	public function testTheUniqueKeysRefuseASecondPairInTheTableItself(): void {
		AB_MCP_Sync_Schema::install();
		global $wpdb;
		$table = AB_MCP_Sync_Schema::table( 'pairs' );
		$row   = static fn( string $source, string $kind, string $live, string $staging ): array => array(
			'source_id'   => $source,
			'kind'        => $kind,
			'live_ref'    => $live,
			'staging_ref' => $staging,
			'canon'       => 'canon/1',
		);
		$suppress = $wpdb->suppress_errors( true );
		try {
			self::assertSame( 1, $wpdb->insert( $table, $row( 'src', 'post', '1', '1' ) ) );
			self::assertFalse( $wpdb->insert( $table, $row( 'src', 'post', '1', '2' ) ), 'The same live object of the same source and kind.' );
			self::assertStringContainsString( 'Duplicate', $wpdb->last_error );
			self::assertFalse( $wpdb->insert( $table, $row( 'src', 'post', '2', '1' ) ), 'The same staging object of the same source and kind.' );
			self::assertStringContainsString( 'Duplicate', $wpdb->last_error );
			self::assertSame( 1, $wpdb->insert( $table, $row( 'src', 'term', '1', '1' ) ), 'Another kind: another ID space.' );
			self::assertSame( 1, $wpdb->insert( $table, $row( 'other', 'post', '1', '1' ) ), 'Another source.' );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		self::assertSame( '3', $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
	}

	public function testAMissingTableOrKeyIsNamedAndThePairsRefuse(): void {
		AB_MCP_Sync_Schema::install();
		global $wpdb;
		$pairs = AB_MCP_Sync_Schema::table( 'pairs' );
		$wpdb->query( "ALTER TABLE `$pairs` DROP INDEX staging_ref" );
		AB_MCP_Sync_Schema::forget();

		self::assertSame( array( 'unique key ' . $pairs . '.staging_ref missing' ), AB_MCP_Sync_Schema::check() );
		self::assertSame( array( 'available' => false, 'reason' => 'missing' ), AB_MCP_Sync_Schema::status() );
		$refused = AB_MCP_Sync_Pairs::find_live( 'src', 'post', '1' );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_staging_unavailable', $refused->get_error_code() );
		self::assertSame( array( 'reason' => 'missing' ), $refused->get_error_data() );

		// The activation makes it again: dbDelta() adds the key.
		$again = AB_MCP_Sync_Schema::install();
		self::assertIsArray( $again );
		self::assertNotSame( array(), $again );
		self::assertTrue( AB_MCP_Sync_Schema::available() );

		$wpdb->query( 'DROP TABLE `' . AB_MCP_Sync_Schema::table( 'steps' ) . '`' );
		AB_MCP_Sync_Schema::forget();
		self::assertSame( array( 'table ' . AB_MCP_Sync_Schema::table( 'steps' ) . ' missing' ), AB_MCP_Sync_Schema::check() );
	}

	public function testAKeyTheDatabaseRefusesMarksTheAttemptAndItIsTriedAgainOnlyAfterTheWait(): void {
		global $wpdb;
		// A table of pairs as an older or broken copy left it: no unique
		// keys, and two rows the key live_ref cannot take.
		$pairs   = AB_MCP_Sync_Schema::table( 'pairs' );
		$columns = array();
		foreach ( AB_MCP_Sync_Schema::COLUMNS['pairs'] as $column => $definition ) {
			$columns[] = "$column $definition";
		}
		$wpdb->query( "CREATE TABLE `$pairs` (" . implode( ', ', $columns ) . ', PRIMARY KEY (id)) ' . $wpdb->get_charset_collate() );
		$wpdb->query( "INSERT INTO `$pairs` (source_id, kind, live_ref, staging_ref) VALUES ('src', 'post', '1', '1'), ('src', 'post', '1', '2')" );

		$suppress = $wpdb->suppress_errors( true );
		try {
			$made = AB_MCP_Sync_Schema::install();
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		self::assertInstanceOf( WP_Error::class, $made );
		self::assertSame( 'ab_mcp_staging_tables_failed', $made->get_error_code() );
		self::assertContains( 'unique key ' . $pairs . '.live_ref missing', $made->get_error_data()['problems'] );
		$stored = (string) AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Sync_Schema::OPTION )['option_value'];
		self::assertMatchesRegularExpression( '/^failed:1:\d+\z/', $stored );
		self::assertSame( 'failed', AB_MCP_Sync_Schema::status()['reason'] );

		// plugins_loaded within RETRY: not again.
		wp_cache_delete( 'alloptions', 'options' );
		$asked = self::queries( array( AB_MCP_Sync_Schema::class, 'maybe_upgrade' ) );
		self::assertSame( array(), preg_grep( '/^(ALTER|CREATE|DESCRIBE)/i', $asked ), 'No dbDelta() on every request of a host that refuses the tables.' );

		// The cause gone and the wait over: plugins_loaded makes it.
		$wpdb->query( "DELETE FROM `$pairs` WHERE staging_ref = '2'" );
		update_option( AB_MCP_Sync_Schema::OPTION, 'failed:1:' . ( time() - AB_MCP_Sync_Schema::RETRY - 1 ) );
		AB_MCP_Sync_Schema::maybe_upgrade();
		self::assertSame( '1', AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Sync_Schema::OPTION )['option_value'] );
		self::assertSame( array(), AB_MCP_Sync_Schema::check() );
	}

	public function testUninstallDropsTheTablesAndTheOptionOfThatSiteOnly(): void {
		global $wpdb;
		AB_MCP_Sync_Schema::install();
		AB_MCP_IT_Sites::reset( 'b' );
		AB_MCP_IT_Sites::request( 'b', 'GET', '/' );
		self::assertCount( 3, self::tables( 'b' ), 'Site B has its own.' );
		self::assertSame( 1, $wpdb->insert( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'source_id' => 'src', 'kind' => 'post', 'live_ref' => '1', 'staging_ref' => '1' ) ) );

		$command = array( PHP_BINARY, __DIR__ . '/bin/uninstall-site.php', AB_MCP_IT_Sites::state_file(), 'a' );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $out . $err );
		self::assertSame( array( 'uninstalled' => true ), json_decode( (string) $out, true ), $out . $err );

		self::assertSame( array(), self::tables( 'a' ) );
		self::assertNull( AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Sync_Schema::OPTION ) );
		self::assertCount( 3, self::tables( 'b' ), 'The other site keeps its tables.' );
		self::assertSame( '1', AB_MCP_IT_Sites::option_row( 'b', AB_MCP_Sync_Schema::OPTION )['option_value'] );
	}
}
