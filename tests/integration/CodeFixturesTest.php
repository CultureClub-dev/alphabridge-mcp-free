<?php
/**
 * The test plugins and themes on both sites (Z8b branch 1, point 2; plan
 * E21): the state at the start, and for each case of plan section 9 that
 * needs one the property it relies on, shown on a running site. What the
 * swap does with them is the work of branches 5 and 6; here only that the
 * fixtures behave as listed (fixtures/code/katalog.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Code;
use AB_MCP_IT_Process;
use AB_MCP_IT_Sites;
use PHPUnit\Framework\TestCase;

final class CodeFixturesTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		AB_MCP_IT_Code::start();
	}

	protected function tearDown(): void {
		AB_MCP_IT_Code::clear();
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
	}

	/**
	 * The active plugins of a site, from its row.
	 *
	 * @return string[]
	 */
	private static function active( string $site ): array {
		$row = AB_MCP_IT_Sites::option_row( $site, 'active_plugins' );
		return null === $row ? array() : array_values( (array) unserialize( (string) $row['option_value'], array( 'allowed_classes' => false ) ) );
	}

	/**
	 * GET a page of a site.
	 *
	 * @return array{status:int,body:string}
	 */
	private static function page( string $site, string $path = '/' ): array {
		$response = wp_remote_get( AB_MCP_IT_Sites::url( $site, $path ), array( 'timeout' => 30, 'redirection' => 0 ) );
		self::assertIsArray( $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}

	private static function on( string $site, array $calls ): array {
		return AB_MCP_IT_Process::calls( $site, array( 'calls' => $calls ) )['results'];
	}

	/* ------------------------------------------------------- the start */

	public function testBothSitesStartAlikeWithEveryUnitAsListed(): void {
		$katalog = AB_MCP_IT_Code::katalog();
		foreach ( array( 'a', 'b' ) as $site ) {
			$active = self::active( $site );
			foreach ( $katalog['plugins'] as $slug => $unit ) {
				self::assertSame( $unit['start']['fassung'], AB_MCP_IT_Code::fassung( $site, 'plugin', $slug ), "$site: $slug" );
				foreach ( $unit['main'] as $main ) {
					self::assertSame( in_array( $main, $unit['start']['aktiv'], true ), in_array( $main, $active, true ), "$site: $main active as listed." );
				}
			}
			foreach ( $katalog['themes'] as $slug => $unit ) {
				self::assertSame( $unit['start'], AB_MCP_IT_Code::fassung( $site, 'theme', $slug ) );
			}
			self::assertFileExists( AB_MCP_IT_Code::path( $site, 'mu-plugin', 'ab-it-beobachter.php' ) );
			// F26: the values a switch of the theme writes, as they stand.
			$options = AB_MCP_IT_Code::theme_options( $site );
			self::assertSame( 'ab-it-eltern', $options['template'] );
			self::assertSame( 'ab-it-kind', $options['stylesheet'] );
			self::assertSame( 'AB IT Kind', $options['current_theme'] );
			$locations = unserialize( (string) $options['theme_switch_menu_locations'], array( 'allowed_classes' => false ) );
			self::assertIsArray( $locations, 'switch_theme() wrote the locations of the theme before.' );
			self::assertGreaterThan( 0, $locations['haupt'] ?? 0 );
			self::assertSame( 1, AB_MCP_IT_Code::observed( $site )['switch_theme'], 'The must-use observer saw the switch of start().' );
			self::assertSame( array( 'neu' => 'ab-it-kind', 'alt' => 'twentytwentyfive' ), AB_MCP_IT_Code::observed( $site )['letzter'] );
			// Activation hooks ran: counters at 1 for the active, 0 for the inactive.
			self::assertSame( 1, AB_MCP_IT_Code::counters( $site, 'ab-it-aktiv' )['aktiviert'] );
			self::assertSame( 1, AB_MCP_IT_Code::counters( $site, 'ab-it-zwei-eins' )['aktiviert'] );
			self::assertSame( 0, AB_MCP_IT_Code::counters( $site, 'ab-it-zwei-zwei' )['aktiviert'] );
			self::assertSame( 0, AB_MCP_IT_Code::counters( $site, 'ab-it-aufrufer' )['aktiviert'] );
			self::assertSame( array( array( 'id' => '1', 'wert' => 'angelegt' ) ), AB_MCP_IT_Sites::rows( $site, 'ab_it_routinen' ), 'F18: the activation made its table.' );
		}
		$home = self::page( 'b' );
		self::assertSame( 200, $home['status'], $home['body'] );
		self::assertStringContainsString( '<p class="ab-it-theme">ab-it-kind</p>', $home['body'] );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::mark( 'b', 'ab-it-aktiv' ) );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::mark( 'a', 'ab-it-kind' ) );
	}

	public function testEveryUnitIsACopyWithTheSameTimeStampNeverALink(): void {
		$repo = (string) realpath( dirname( __DIR__, 2 ) );
		foreach ( array( 'plugins', 'themes', 'mu-plugins' ) as $folder ) {
			foreach ( (array) glob( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/' . $folder . '/ab-it-*' ) as $unit ) {
				$paths = is_dir( (string) $unit ) ? array_map( static fn( $f ) => $unit . '/' . $f, AB_MCP_IT_Code::files( (string) $unit ) ) : array( $unit );
				self::assertFalse( is_link( (string) $unit ), "$unit is a link." );
				foreach ( $paths as $path ) {
					self::assertFalse( is_link( (string) $path ), "$path is a link." );
					self::assertStringStartsNotWith( $repo . '/', (string) realpath( (string) $path ), "$path leads into the checkout." );
					self::assertSame( AB_MCP_IT_Code::MTIME, filemtime( (string) $path ) );
				}
			}
		}
	}

	public function testClearRemovesTheTestUnitsAndNothingElse(): void {
		AB_MCP_IT_Code::clear( array( 'a' ) );
		foreach ( array( 'plugins', 'themes', 'mu-plugins' ) as $folder ) {
			self::assertSame( array(), (array) glob( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/' . $folder . '/ab-it-*' ) );
		}
		self::assertFileExists( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/plugins/hello.php' );
		self::assertDirectoryExists( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/themes/twentytwentyfive' );
		self::assertTrue( is_link( AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/plugins/alphabridge-mcp' ), 'The plugin under test stays.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'b', 'plugin', 'ab-it-aktiv' ), 'Site b untouched.' );
		$this->expectException( \InvalidArgumentException::class );
		AB_MCP_IT_Code::remove( 'a', 'plugin', 'hello' );
	}

	/* ----------------------------------- versions as the cases need them */

	public function testAnotherVersionRunsAtTheNextRequestAndF39OnlyItsBytesDiffer(): void {
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-aktiv', '2.0.0' );
		self::assertSame( '2.0.0', AB_MCP_IT_Code::fassung( 'b', 'plugin', 'ab-it-aktiv' ) );
		self::assertSame( '2.0.0', AB_MCP_IT_Code::mark( 'b', 'ab-it-aktiv' ) );
		// F39: the same size, version and time stamp, other bytes; the mark says which ran.
		$file   = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-zeitstempel' ) . '/ab-it-zeitstempel.php';
		$before = array( filesize( $file ), filemtime( $file ), hash_file( 'sha256', $file ) );
		self::assertSame( 'alt', AB_MCP_IT_Code::mark( 'a', 'ab-it-zeitstempel' ) );
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-zeitstempel', 'neu' );
		clearstatcache();
		self::assertSame( $before[0], filesize( $file ) );
		self::assertSame( $before[1], filemtime( $file ) );
		self::assertNotSame( $before[2], hash_file( 'sha256', $file ) );
		self::assertSame( 'neu', AB_MCP_IT_Code::mark( 'a', 'ab-it-zeitstempel' ), 'OPcache is off for the servers; the new bytes ran.' );
	}

	public function testANewVersionWithAFatalErrorStopsTheSite(): void {
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-aktiv', 'fatal' );
		self::assertSame( 500, self::page( 'b' )['status'] );
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-aktiv', '1.0.0' );
		self::assertSame( 200, self::page( 'b' )['status'] );
	}

	public function testF34TheMigrationWithoutANewNumberAndTheOneAfterWhichBothVersionsFail(): void {
		self::assertSame( 200, self::page( 'b' )['status'] );
		self::assertSame( '1', AB_MCP_IT_Sites::option_row( 'b', 'ab_it_migration_schema' )['option_value'] );
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-migration', '1.0.0-migration' );
		self::assertSame( 200, self::page( 'b' )['status'] );
		self::assertSame( '2', AB_MCP_IT_Sites::option_row( 'b', 'ab_it_migration_schema' )['option_value'] );
		self::assertSame( 'v2', AB_MCP_IT_Sites::option_row( 'b', 'ab_it_migration_daten' )['option_value'] );
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-migration', '1.0.0' );
		self::assertSame( 200, self::page( 'b' )['status'], 'The old version understands schema 2.' );
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-migration', '2.0.0-bruch' );
		self::assertSame( 500, self::page( 'b' )['status'] );
		self::assertSame( '3', AB_MCP_IT_Sites::option_row( 'b', 'ab_it_migration_schema' )['option_value'], 'It migrated before it failed.' );
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-migration', '1.0.0' );
		self::assertSame( 500, self::page( 'b' )['status'], 'The old version fails as well now.' );
	}

	public function testF46ThePluginWritesIntoItsFolderOnEveryRequest(): void {
		$log = AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-schreiber' ) . '/log/zugriffe.log';
		self::assertFileDoesNotExist( $log, 'No request since start(): its activation came after init.' );
		self::page( 'b' );
		self::page( 'b', '/?p=1' );
		self::assertSame( 2, count( file( $log ) ), 'One line per request.' );
		self::assertDirectoryDoesNotExist( AB_MCP_IT_Code::source( 'plugin', 'ab-it-schreiber', '1.0.0' ) . '/log', 'Nothing in the checkout.' );
	}

	public function testF47TheDataFileIsInTheFirstVersionOnly(): void {
		$csv = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-daten' ) . '/data/export.csv';
		self::assertFileExists( $csv );
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-daten', '1.1.0' );
		self::assertFileDoesNotExist( $csv );
	}

	/* ------------------------------------------------ routines, hooks */

	public function testF48WithoutTheServiceTheCallerFailsOnTheHomePageAndNoDeactivationRoutineRan(): void {
		self::on( 'b', array( array( 'call' => 'activate_plugin', 'args' => array( 'ab-it-aufrufer/ab-it-aufrufer.php' ), 'expect' => 'result' ) ) );
		$home = self::page( 'b' );
		self::assertSame( 200, $home['status'] );
		self::assertStringContainsString( '<meta name="ab-it-gruss" content="Gruss vom Dienst 1.0.0">', $home['body'] );
		AB_MCP_IT_Code::remove( 'b', 'plugin', 'ab-it-dienst' );
		self::assertSame( 500, self::page( 'b' )['status'], 'The second plugin fails there.' );
		self::assertContains( 'ab-it-dienst/ab-it-dienst.php', self::active( 'b' ), 'Still listed: WordPress skips a missing file as it loads.' );
		self::assertSame( 0, AB_MCP_IT_Code::counters( 'b', 'ab-it-dienst' )['deaktiviert'] );
		// WordPress deactivates it silently (the screen Plugins does so,
		// wp-admin/plugins.php:639): no deactivation routine.
		self::on( 'b', array( array( 'call' => 'validate_active_plugins' ) ) );
		self::assertNotContains( 'ab-it-dienst/ab-it-dienst.php', self::active( 'b' ) );
		self::assertSame( 0, AB_MCP_IT_Code::counters( 'b', 'ab-it-dienst' )['deaktiviert'] );
		// Switched on again as by hand: its activation routine runs.
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-dienst', '1.0.0' );
		self::on( 'b', array( array( 'call' => 'activate_plugin', 'args' => array( 'ab-it-dienst/ab-it-dienst.php' ), 'expect' => 'result' ) ) );
		self::assertSame( 2, AB_MCP_IT_Code::counters( 'b', 'ab-it-dienst' )['aktiviert'] );
		self::assertSame( 200, self::page( 'b' )['status'] );
	}

	public function testF18AndF49TheRoutinesCountAndWriteTheirData(): void {
		self::on( 'a', array( array( 'call' => 'deactivate_plugins', 'args' => array( 'ab-it-routinen/ab-it-routinen.php' ) ) ) );
		self::assertSame( 1, AB_MCP_IT_Code::counters( 'a', 'ab-it-routinen' )['deaktiviert'] );
		self::assertSame( 'ja', AB_MCP_IT_Sites::option_row( 'a', 'ab_it_routinen_deaktiviert' )['option_value'] );
		self::assertCount( 1, AB_MCP_IT_Sites::rows( 'a', 'ab_it_routinen' ), 'The table stays until the uninstall routine.' );
		self::on( 'a', array( array( 'call' => 'uninstall_plugin', 'args' => array( 'ab-it-routinen/ab-it-routinen.php' ) ) ) );
		self::assertSame( 1, AB_MCP_IT_Code::counters( 'a', 'ab-it-routinen' )['deinstalliert'] );
		global $wpdb;
		self::assertSame( array(), $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', AB_MCP_IT_Sites::prefix( 'a' ) . 'ab\_it\_routinen' ) ), 'uninstall.php dropped the table.' );
		self::assertDirectoryExists( AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-routinen' ), 'uninstall_plugin() leaves the folder.' );
	}

	public function testF51TheLonePluginIsMissedByNoneAndCountsItsReturn(): void {
		self::on( 'b', array( array( 'call' => 'deactivate_plugins', 'args' => array( 'ab-it-allein/ab-it-allein.php' ) ) ) );
		self::assertSame( 1, AB_MCP_IT_Code::counters( 'b', 'ab-it-allein' )['deaktiviert'] );
		self::assertSame( 200, self::page( 'b' )['status'], 'The site runs without it.' );
		self::assertNull( AB_MCP_IT_Code::mark( 'b', 'ab-it-allein' ) );
		self::on( 'b', array( array( 'call' => 'activate_plugin', 'args' => array( 'ab-it-allein/ab-it-allein.php' ), 'expect' => 'result' ) ) );
		self::assertSame( 2, AB_MCP_IT_Code::counters( 'b', 'ab-it-allein' )['aktiviert'] );
	}

	public function testF8TheDependencyIsCheckedAsWordPressChecksIt(): void {
		self::on( 'a', array( array( 'call' => 'deactivate_plugins', 'args' => array( array( 'ab-it-braucht/ab-it-braucht.php', 'ab-it-basis/ab-it-basis.php' ) ) ) ) );
		$refused = self::on( 'a', array( array( 'call' => 'activate_plugin', 'args' => array( 'ab-it-braucht/ab-it-braucht.php' ), 'as' => 'braucht' ) ) );
		self::assertSame( 'plugin_missing_dependencies', $refused['braucht']['wp_error']['code'] ?? null, json_encode( $refused ) );
		self::on( 'a', array( array( 'call' => 'activate_plugin', 'args' => array( 'ab-it-basis/ab-it-basis.php' ), 'expect' => 'result' ), array( 'call' => 'activate_plugin', 'args' => array( 'ab-it-braucht/ab-it-braucht.php' ), 'expect' => 'result' ) ) );
		self::assertContains( 'ab-it-braucht/ab-it-braucht.php', self::active( 'a' ) );
	}

	public function testF10AndF26TheChoiceOfThemeWhoseParentIsMissingAndTheObserverWithoutTheFolder(): void {
		// F10: on b another child theme, its parent missing on a.
		self::on( 'b', array( array( 'call' => 'switch_theme', 'args' => array( 'ab-it-anderes-kind' ) ) ) );
		self::assertSame( 'ab-it-anderes-eltern', AB_MCP_IT_Code::theme_options( 'b' )['template'] );
		self::assertSame( 'ab-it-anderes-kind', AB_MCP_IT_Code::theme_options( 'b' )['stylesheet'] );
		AB_MCP_IT_Code::remove( 'a', 'theme', 'ab-it-anderes-eltern' );
		self::assertSame( '', AB_MCP_IT_Code::fassung( 'a', 'theme', 'ab-it-anderes-eltern' ) );
		// F26: the folder of the active theme gone; the observer still counts.
		$before = AB_MCP_IT_Code::theme_options( 'a' );
		AB_MCP_IT_Code::remove( 'a', 'theme', 'ab-it-kind' );
		self::assertSame( $before, AB_MCP_IT_Code::theme_options( 'a' ), 'Removing the folder writes no option.' );
		self::on( 'a', array( array( 'call' => 'switch_theme', 'args' => array( 'ab-it-eltern' ) ) ) );
		self::assertSame( 2, AB_MCP_IT_Code::observed( 'a' )['switch_theme'] );
		self::assertSame( array( 'neu' => 'ab-it-eltern', 'alt' => 'ab-it-kind' ), AB_MCP_IT_Code::observed( 'a' )['letzter'] );
	}
}
