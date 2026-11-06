<?php
/**
 * The environment in wp_site_diff on two WordPress sites (Z8 decision 14,
 * «Lesende Bestandsaufnahme der übrigen Arten»; branch 7): site B is the
 * staging site, in processes of its own, and packs its environment
 * (wp_site_export with purpose=env); site A, this process, takes it over and
 * reads its own.
 *
 * - The report over both: plugins of the same and of another version, the
 *   themes and WordPress counted, must-use plugins and drop-ins equal or
 *   differing by their SHA-256 (a drop-in changed at the host), the files
 *   under uploads outside the file lists of attachments and the language
 *   files counted or differing, tables without an engine counted or
 *   differing by their rows; nothing of either site written.
 * - No constant that holds a secret, neither in the answer nor in a file of
 *   the protected store of either site.
 * - The packages of the environment one whole series in order; a package of
 *   the environment is no package of the inventory and the other way round;
 *   a line of another shape refuses the package.
 * - The environment of this site in parts: the same entries; the uploads
 *   counted over parts outside the file lists of attachments.
 * - Without environment: one entry, not compared, with the way.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Protected_Store;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Env;
use AB_MCP_Sync_Export;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-env.php';

final class SyncEnvTest extends TestCase {

	/** The files this test writes, relative to wp-content, on both sites. */
	private const FILES = array(
		'plugins/ab-it-env-same/ab-it-env-same.php',
		'plugins/ab-it-env-version/ab-it-env-version.php',
		'mu-plugins/ab-it-env-same.php',
		'mu-plugins/ab-it-env-differs.php',
		'mu-plugins/ab-it-env-dir/inner.php',
		'db-error.php',
		'php-error.php',
		'languages/ab-it-env.mo',
		'uploads/ab-it-env/loose.txt',
	);

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		wp_set_current_user( self::admin() );
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
	}

	protected function tearDown(): void {
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Report::$env_lines = null;
		AB_MCP_Sync_Env::$clock        = null;
		self::unwrite();
	}

	public static function tearDownAfterClass(): void {
		self::unwrite();
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
	}

	private static function admin(): int {
		return (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'];
	}

	private static function wipe(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		self::remove_tree( $root . '/ab-mcp-store', $root );
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

	/** wp-content of a site. */
	private static function content( string $site ): string {
		return AB_MCP_IT_Sites::docroot( $site ) . '/wp-content';
	}

	/** A file under wp-content of a site. */
	private static function write( string $site, string $rel, string $bytes ): void {
		$path = self::content( $site ) . '/' . $rel;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		file_put_contents( $path, $bytes );
	}

	/** Every file and folder this test made, on both sites. */
	private static function unwrite(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		foreach ( array( 'a', 'b' ) as $site ) {
			foreach ( array( 'plugins/ab-it-env-same', 'plugins/ab-it-env-version', 'mu-plugins', 'languages', 'uploads', 'db-error.php', 'php-error.php' ) as $rel ) {
				self::remove_tree( self::content( $site ) . '/' . $rel, $root );
			}
		}
		wp_cache_delete( 'plugins', 'plugins' );
	}

	/** A plugin of a version. */
	private static function plugin( string $name, string $version ): string {
		return "<?php\n/**\n * Plugin Name: $name\n * Version: $version\n */\n";
	}

	/** The files and tables both sites differ in, as the main case sets them. */
	private static function differ(): void {
		foreach ( array( 'a', 'b' ) as $site ) {
			self::write( $site, 'plugins/ab-it-env-same/ab-it-env-same.php', self::plugin( 'Env same', '1.0' ) );
			self::write( $site, 'plugins/ab-it-env-version/ab-it-env-version.php', self::plugin( 'Env version', 'a' === $site ? '1.0' : '2.0' ) );
			self::write( $site, 'mu-plugins/ab-it-env-same.php', "<?php\n// The same on both.\n" );
			self::write( $site, 'mu-plugins/ab-it-env-differs.php', "<?php\n// On $site.\n" );
			self::write( $site, 'mu-plugins/ab-it-env-dir/inner.php', "<?php\n// Not loaded by WordPress.\n" );
			self::write( $site, 'db-error.php', "<?php\necho 'Database down on $site.';\n" );
			self::write( $site, 'php-error.php', "<?php\necho 'Error.';\n" );
			self::write( $site, 'languages/ab-it-env.mo', 'mo' );
		}
		self::write( 'b', 'uploads/ab-it-env/loose.txt', 'Only on staging.' );
		// The must-use loader of AlphaBridge on staging only: group U, no must-use plugin.
		self::write( 'b', 'mu-plugins/' . AB_MCP_Sync_Env::LOADER, "<?php\n// A loader.\n" );
		wp_cache_delete( 'plugins', 'plugins' );
		self::sql( 'a', 'CREATE TABLE `{p}abenv_same` ( id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, v VARCHAR(20) )' );
		self::sql( 'b', 'CREATE TABLE `{p}abenv_same` ( id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, v VARCHAR(20) )' );
		self::sql( 'a', "INSERT INTO `{p}abenv_same` ( v ) VALUES ( 'x' ), ( 'y' )" );
		self::sql( 'b', "INSERT INTO `{p}abenv_same` ( v ) VALUES ( 'p' ), ( 'q' )" );
		self::sql( 'a', 'CREATE TABLE `{p}abenv_rows` ( id INT NOT NULL PRIMARY KEY )' );
		self::sql( 'b', 'CREATE TABLE `{p}abenv_rows` ( id INT NOT NULL PRIMARY KEY )' );
		self::sql( 'b', 'INSERT INTO `{p}abenv_rows` ( id ) VALUES ( 1 ), ( 2 ), ( 3 )' );
	}

	/** One statement on the tables of a site ({p}: its prefix). */
	private static function sql( string $site, string $sql ): void {
		global $wpdb;
		self::assertNotFalse( $wpdb->query( str_replace( '{p}', AB_MCP_IT_Sites::prefix( $site ), $sql ) ), (string) $wpdb->last_error ); // phpcs:ignore WordPress.DB
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

	/** B packs one purpose (a series) and A takes it over: the package ids. */
	private static function staged( string $purpose, ?int $objects = null ): array {
		$ids    = array();
		$cursor = null;
		do {
			$step = array(
				'op'      => 'export',
				'purpose' => $purpose,
				'as'      => 'package',
			);
			if ( null !== $cursor ) {
				$step['cursor'] = $cursor;
			}
			if ( null !== $objects ) {
				$step['objects'] = $objects;
			}
			$package = self::b( array( $step ) )['package'];
			$cursor  = $package['cursor'];
			self::assertSame( $purpose, $package['purpose'] );
			$state = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $package['package_id'],
					'manifest'   => $package['manifest'],
					'purpose'    => $purpose,
				)
			);
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
			$steps = array();
			foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
				$steps[] = array(
					'op'         => 'export_chunk',
					'package_id' => $package['package_id'],
					'chunk'      => $i,
					'as'         => 'c' . $i,
				);
			}
			$data = self::b( $steps );
			foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
				$state = AB_MCP_Sync_Import::call(
					array(
						'package_id' => $package['package_id'],
						'chunk'      => $i,
						'data'       => $data[ 'c' . $i ]['data'],
					)
				);
				self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
			}
			self::assertTrue( $state['complete'] );
			$ids[] = $package['package_id'];
		} while ( null !== $cursor && count( $ids ) < 50 );
		self::assertNull( $cursor, 'The series ended.' );
		return $ids;
	}

	/** Packages of B taken over here, removed on B. */
	private static function gone_on_b( array $ids ): void {
		$steps = array();
		foreach ( $ids as $id ) {
			$steps[] = array( 'op' => 'store_remove', 'area' => 'out', 'gid' => $id );
		}
		self::b( $steps );
	}

	/** wp_site_diff in this process, worked out to its end and paged: every entry. */
	private static function report( array $args, int &$calls = 0 ): array {
		$answer = AB_MCP_Sync_Report::call( $args );
		$calls  = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
			++$calls;
			self::assertLessThan( 300, $calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$all   = $answer['entries'];
		$page  = $answer;
		$pages = 0;
		while ( null !== $page['cursor'] ) {
			$page = AB_MCP_Sync_Report::call(
				array(
					'report_id' => $answer['report_id'],
					'cursor'    => $page['cursor'],
				)
			);
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
			++$pages;
			self::assertLessThan( 300, $pages, 'The cursor moves on.' );
		}
		$answer['all'] = $all;
		return $answer;
	}

	/** The entries of the environment, by subtype and ref. */
	private static function env( array $entries ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			if ( 'environment' === $entry['kind'] ) {
				$out[ $entry['subtype'] . ':' . $entry['ref'] ] = $entry;
			}
		}
		return $out;
	}

	private static function said( array $entry ): array {
		return array( $entry['result'], $entry['action'], $entry['locks'][0]['stage'] ?? null );
	}

	private static function refused( $result, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_message();
	}

	/* ---------------------------------------------------------- the report */

	public function testTheEnvironmentOfBothSitesIsReadAndComparedNeverEqualWhereOnlyCounted(): void {
		self::pair();
		self::differ();
		$before = self::options_of_a();
		$answer = self::report(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => self::staged( 'env', 6 ),
			)
		);
		$env = self::env( $answer['all'] );

		self::assertSame( array( 'counted', 'locked', 'Z8b' ), self::said( $env['plugin:ab-it-env-same/ab-it-env-same.php'] ), 'The same version: counted, contents not compared.' );
		self::assertSame( 'not compared', $env['plugin:ab-it-env-same/ab-it-env-same.php']['contents'] );
		self::assertSame( array( 'differs', 'locked', 'Z8b' ), self::said( $env['plugin:ab-it-env-version/ab-it-env-version.php'] ) );
		self::assertSame( array( 'version' ), $env['plugin:ab-it-env-version/ab-it-env-version.php']['differs'] );
		self::assertSame( array( '2.0', '1.0' ), array( $env['plugin:ab-it-env-version/ab-it-env-version.php']['staging']['version'], $env['plugin:ab-it-env-version/ab-it-env-version.php']['live']['version'] ) );
		self::assertSame( array( 'counted', 'locked', 'Z8b' ), self::said( $env['plugin:alphabridge-mcp/alphabridge-mcp.php'] ), 'This plugin, the same version and active on both.' );
		self::assertTrue( $env['plugin:alphabridge-mcp/alphabridge-mcp.php']['live']['active'] );
		self::assertFalse( $env['plugin:ab-it-env-same/ab-it-env-same.php']['live']['active'] );
		self::assertSame( array( 'counted', 'locked', 'Z8b' ), self::said( $env['theme:twentytwentyfive'] ) );
		self::assertSame( array( 'counted', 'locked', 'Z8b' ), self::said( $env['core:wordpress'] ) );
		self::assertSame( array( 'equal', 'none', null ), self::said( $env['core:locale'] ) );

		self::assertSame( array( 'equal', 'none', null ), self::said( $env['mu_plugin:ab-it-env-same.php'] ), 'Read by its SHA-256: equal.' );
		self::assertSame( array( 'differs', 'locked', 'Z8b' ), self::said( $env['mu_plugin:ab-it-env-differs.php'] ) );
		self::assertSame( array( 'contents' ), $env['mu_plugin:ab-it-env-differs.php']['differs'] );
		self::assertSame( array( 'differs', 'host', null ), self::said( $env['dropin:db-error.php'] ), 'A drop-in with another hash: changed at the host.' );
		self::assertSame( array( 'equal', 'none', null ), self::said( $env['dropin:php-error.php'] ) );
		self::assertArrayNotHasKey( 'dropin:object-cache.php', $env, 'A drop-in on neither side.' );

		self::assertSame( array( 'counted', 'locked', 'Z8b' ), self::said( $env['files:languages'] ) );
		self::assertSame( array( 1, 2 ), array( $env['files:languages']['live']['files'], $env['files:languages']['live']['bytes'] ) );
		self::assertSame( array( 'counted', 'locked', 'Z8b' ), self::said( $env['files:mu-plugins'] ), 'The subfolder of the must-use folder, counted.' );
		self::assertSame( 1, $env['files:mu-plugins']['live']['files'] );
		self::assertSame( array( 'differs', 'locked', 'Z8b' ), self::said( $env['files:uploads'] ), 'A file outside the file lists of attachments only on staging.' );
		self::assertSame( array( 1, 0 ), array( $env['files:uploads']['staging']['files'], $env['files:uploads']['live']['files'] ) );
		self::assertSame( array( 'counted', 'locked', 'Z8b' ), self::said( $env['files:fonts'] ) );

		self::assertSame( array( 'counted', 'locked', 'Z8d' ), self::said( $env['table:abenv_same'] ), 'The same rows and schema: counted, never equal.' );
		self::assertSame( array( 'rows' => 2 ), $env['table:abenv_same']['live'] );
		self::assertSame( array( 'differs', 'locked', 'Z8d' ), self::said( $env['table:abenv_rows'] ) );
		self::assertSame( array( 'rows' ), $env['table:abenv_rows']['differs'] );
		foreach ( array_keys( $env ) as $key ) {
			self::assertStringStartsNotWith( 'table:ab_mcp_staging', $key, 'The tables of the takeover are no table without an engine.' );
			self::assertNotContains( substr( $key, 6 ), array( 'posts', 'options', 'users', 'usermeta', 'comments', 'links' ), 'A table of the core is none either.' );
		}

		self::assertSame( array( 'equal', 'none', null ), self::said( $env['host:php'] ) );
		self::assertSame( array( 'equal', 'none', null ), self::said( $env['host:database'] ) );
		self::assertSame( array( 'equal', 'none', null ), self::said( $env['host:constant:WP_DEBUG'] ) );
		self::assertSame( array( 'differs', 'host', null ), self::said( $env['host:constant:WP_DEBUG_LOG'] ), 'A path of each site, compared by its hash.' );
		self::assertSame( array( 'differs', 'host', null ), self::said( $env['host:mu_loader'] ) );
		self::assertSame( array( 'present', 'absent' ), array( $env['host:mu_loader']['staging']['value'], $env['host:mu_loader']['live']['value'] ) );
		self::assertArrayNotHasKey( 'mu_plugin:' . AB_MCP_Sync_Env::LOADER, $env, 'The loader is group U.' );
		self::assertSame( array( 'equal', 'none', null ), self::said( $env['host:htaccess'] ), 'No .htaccess on either site.' );
		self::assertSame( 'absent', $env['host:htaccess']['live']['value'] );
		self::assertSame( 'not shown', $env['host:constant:WP_DEBUG_LOG']['live']['value'] );

		foreach ( $env as $key => $entry ) {
			if ( in_array( $entry['subtype'], array( 'plugin', 'theme', 'table', 'files' ), true ) || 'core:wordpress' === $key ) {
				self::assertNotSame( 'equal', $entry['result'], $key . ': counted or summed is never equal.' );
			}
		}
		self::assertSame( count( $env ), $answer['environment']['counts']['entries'] );
		self::assertSame( 2, count( array_filter( $answer['blocked'], static fn( $b ) => in_array( $b['key'], array( 'env:plugin', 'env:table' ), true ) ) ), 'The locks of the environment with the others.' );
		self::assertSame( range( 1, $answer['total'] ), array_column( $answer['all'], 'n' ), 'The environment numbered on after the objects.' );
		self::assertStringNotContainsString( '"sha256"', (string) json_encode( $answer ), 'No hash in an answer.' );
		self::assertStringNotContainsString( '"schema"', (string) json_encode( $answer ) );
		self::assertSame( $before, self::options_of_a(), 'Nothing of the site written.' );
	}

	/** The options of A but the records of a call and of this plugin. */
	private static function options_of_a(): string {
		global $wpdb;
		return hash( 'sha256', (string) json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name NOT LIKE 'ab\\_mcp\\_%' AND option_name NOT IN ( 'cron' ) ORDER BY option_name", ARRAY_A ) ) ); // phpcs:ignore WordPress.DB
	}

	public function testNoConstantThatHoldsASecretIsInTheAnswerOrInAnyFileOfTheStore(): void {
		self::pair();
		$answer   = self::report(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => self::staged( 'env' ),
			)
		);
		$text     = (string) json_encode( $answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$secrets  = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'DB_HOST' );
		$values   = array();
		$config_b = (string) file_get_contents( AB_MCP_IT_Sites::docroot( 'b' ) . '/wp-config.php' );
		foreach ( $secrets as $name ) {
			$values[] = (string) constant( $name );
			self::assertSame( 1, preg_match( "/define\\( '$name', '([^']+)' \\);/", $config_b, $m ), $name . ' of site B.' );
			$values[] = $m[1];
		}
		$names = array_merge( $secrets, array( 'DB_PASSWORD', 'DB_USER', 'DB_NAME' ) );

		// Every file of the store of both sites, unpacked where it is a gzip.
		$root  = (string) AB_MCP_IT_Sites::state()['root'] . '/ab-mcp-store';
		$texts = array( 'the answer' => $text );
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$bytes = (string) file_get_contents( (string) $file );
			$plain = "\x1f\x8b" === substr( $bytes, 0, 2 ) ? gzdecode( $bytes ) : $bytes;
			$texts[ (string) $file ] = false === $plain ? $bytes : $plain;
		}
		self::assertGreaterThan( 5, count( $texts ), 'The packages of both sites and the report.' );
		self::assertNotEmpty( array_filter( $texts, static fn( $t ) => false !== strpos( $t, '"constant:WP_DEBUG"' ) ), 'The environment is in the store.' );
		foreach ( $texts as $where => $t ) {
			foreach ( $values as $value ) {
				self::assertStringNotContainsString( $value, $t, "A secret value in $where." );
			}
			foreach ( $names as $name ) {
				self::assertStringNotContainsString( '"constant:' . $name . '"', $t, "$name in $where." );
			}
		}
	}

	/* ------------------------------------------------------------ packages */

	public function testThePackagesOfTheEnvironmentAreOneWholeSeriesInItsOrder(): void {
		self::pair();
		$inventory = self::staged( 'inventory' );
		$env       = self::staged( 'env', 6 );
		self::assertGreaterThan( 1, count( $env ), 'A series of packages.' );
		$series = 'ab_mcp_sync_report_environment_series';
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array_reverse( $env ) ) ), $series );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array_slice( $env, 1 ) ) ), $series );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array_slice( $env, 0, -1 ) ) ), $series );
		// Three open exports an account at most (decision 8): the packages
		// taken over here go on site B.
		self::gone_on_b( $env );
		$other = self::staged( 'env', 6 );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array_merge( array( $env[0] ), array_slice( $other, 1 ) ) ) ), $series );
		self::assertIsArray( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => $other ) ), 'The other whole series.' );

		// Each package where it belongs, each once.
		$held = 'ab_mcp_sync_import_purpose_held';
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => $inventory ) ), 'ab_mcp_sync_report_environment' );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => array_merge( $inventory, array( $env[0] ) ) ) ), $held );
		self::gone_on_b( $other );
		$another = self::staged( 'inventory' );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array( $another[0] ) ) ), $held );
		$message = self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $env ) ), $held );
		self::assertStringContainsString( 'environment', $message );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array( $env[0], $env[0] ) ) ), 'ab_mcp_sync_report_environment' );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => 'x' ) ), 'ab_mcp_sync_report_environment' );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array( 'a' => $env[0] ) ) ), 'ab_mcp_sync_report_environment' );
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array_fill( 0, AB_MCP_Sync_Report::MAX_ENVIRONMENT + 1, $env[0] ) ) ), 'ab_mcp_sync_report_environment' );

		// A cursor of the environment goes on with the environment only.
		self::gone_on_b( $another );
		$first = self::b( array( array( 'op' => 'export', 'purpose' => 'env', 'objects' => 6, 'as' => 'p' ) ) )['p'];
		self::assertSame( 'env', $first['cursor']['kind'] );
		$wrong = self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'cursor' => $first['cursor'], 'as' => 'p', 'expect' => 'error' ) ) )['p'];
		self::assertSame( 'ab_mcp_sync_cursor_invalid', $wrong['code'] );
	}

	public function testALineOfTheEnvironmentOfAnotherShapeRefusesThePackage(): void {
		self::pair();
		foreach ( array( 'shaped' => array( 'env' => 'core', 'ref' => 'wordpress', 'value' => '7.1.3' ), 'not' => array( 'env' => 'core', 'ref' => 'wordpress', 'value' => '7.1.3', 'secret' => 'x' ) ) as $case => $line ) {
			$id      = bin2hex( random_bytes( 16 ) );
			$text    = \AB_MCP_Sync_Canon::encode( $line ) . "\n";
			$chunk   = (string) gzencode( $text, 6 );
			$package = array(
				'package_id'  => $id,
				'purpose'     => 'env',
				'report_hash' => null,
				'kinds'       => array(),
				'objects'     => 1,
				'unpacked'    => strlen( $text ),
				'chunks'      => array( array( 'bytes' => strlen( $chunk ), 'sha256' => hash( 'sha256', $chunk ) ) ),
				'bytes'       => strlen( $chunk ),
				'sha256'      => hash( 'sha256', $chunk ),
			);
			$signed  = self::b( array( array( 'op' => 'sign_manifest', 'as' => 'signed', 'package' => $package ) ) )['signed'];
			self::assertIsArray( AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'manifest' => $signed, 'purpose' => 'env' ) ) );
			$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 0, 'data' => base64_encode( $chunk ) ) );
			if ( 'shaped' === $case ) {
				self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
				self::assertTrue( $state['complete'] );
				self::assertSame( 0, $state['held'] );
				$taken = AB_MCP_Sync_Import::taken( $id, 'env' );
				self::assertIsArray( $taken );
				self::assertSame( array( $line ), $taken['lines'] );
				self::refused( AB_MCP_Sync_Import::taken( $id ), 'ab_mcp_sync_import_purpose_held' );
				// Signed, but no page: no part of a series.
				self::refused( AB_MCP_Sync_Report::call( array( 'packages' => self::staged( 'inventory' ), 'environment' => array( $id ) ) ), 'ab_mcp_sync_report_environment_series' );
			} else {
				self::assertInstanceOf( WP_Error::class, $state );
				self::assertStringContainsString( 'A line is not one of the environment', $state->get_error_message() );
			}
		}
	}

	/* ------------------------------------------------------------- in parts */

	public function testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries(): void {
		self::pair();
		self::differ();
		$args  = array(
			'packages'    => self::staged( 'inventory' ),
			'environment' => self::staged( 'env' ),
		);
		$calls = 0;
		$whole = self::report( $args, $calls );
		self::assertSame( 1, $calls, 'A small site in one call.' );
		AB_MCP_Sync_Report::$env_lines = 2;
		$parts                         = self::report( $args, $calls );
		self::assertGreaterThan( 3, $calls, 'Two lines a part: a table a part at least.' );
		$strip = static fn( array $entries ): array => array_map( static fn( $e ) => array_diff_key( $e, array( 'n' => 0 ) ), array_values( self::env( $entries ) ) );
		self::assertSame( $strip( $whole['all'] ), $strip( $parts['all'] ), 'The same environment over parts.' );
		self::assertSame( $whole['environment'], $parts['environment'] );

		// A call with too little left of its budget reads no part.
		AB_MCP_Sync_Report::$env_lines = null;
		$now                           = 1000.0;
		AB_MCP_Sync_Env::$clock        = static function () use ( &$now ) {
			$now += 40;
			return $now;
		};
		$answer = AB_MCP_Sync_Report::call( $args );
		self::assertIsArray( $answer );
		self::assertFalse( $answer['done'] );
		self::assertSame( 0, $answer['environment']['parts'], 'Less than ENV_MIN seconds left: the next call reads it.' );
		AB_MCP_Sync_Env::$clock = null;
		$answer                 = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
		self::assertTrue( $answer['done'] );
	}

	public function testTheUploadsAreCountedOverPartsOutsideTheFileListsOfAttachments(): void {
		$uploads = self::content( 'a' ) . '/uploads';
		self::write( 'a', 'uploads/2026/10/photo.jpg', 'jpeg' );
		self::write( 'a', 'uploads/2026/10/photo-150x150.jpg', 'th' );
		$id = wp_insert_attachment( array( 'post_title' => 'Photo', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), $uploads . '/2026/10/photo.jpg' );
		self::assertIsInt( $id );
		update_post_meta( $id, '_wp_attachment_metadata', array( 'file' => '2026/10/photo.jpg', 'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg' ) ) ) );
		$bytes = 0;
		for ( $i = 0; $i < 150; $i++ ) {
			self::write( 'a', sprintf( 'uploads/loose/%03d/f.txt', $i ), str_repeat( 'x', $i ) );
			$bytes += $i;
		}
		self::write( 'a', 'uploads/fonts/a.woff2', 'font' );
		self::write( 'a', 'uploads/ab-mcp-undo/x.json', '{}' );
		self::write( 'a', 'uploads/ab-mcp-store-0123/x', 'x' );

		$want = array( 'attached' => 2, 'bytes' => $bytes, 'complete' => true, 'env' => 'files', 'files' => 150, 'ref' => 'uploads', 'skipped' => 0, 'why' => null );
		$read = AB_MCP_Sync_Env::read( null, 60 );
		self::assertIsArray( $read );
		self::assertNull( $read['state'] );
		$lines = array_column( array_filter( $read['lines'], static fn( $l ) => 'files' === $l['env'] ), null, 'ref' );
		self::assertSame( $want, $lines['uploads'], 'Without fonts and the folders of AlphaBridge, the files of the attachment apart.' );
		self::assertSame( array( 1, 4 ), array( $lines['fonts']['files'], $lines['fonts']['bytes'] ) );

		// Each look at the clock a second later, two seconds a part.
		$now                    = 0.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$now ) {
			return ++$now;
		};
		$state = null;
		$parts = array();
		do {
			$read = AB_MCP_Sync_Env::read( $state, 2 );
			self::assertIsArray( $read );
			$parts[] = $read['lines'];
			$state   = $read['state'];
		} while ( null !== $state && count( $parts ) < 200 );
		self::assertNull( $state );
		self::assertGreaterThan( 2, count( $parts ), 'Counted over parts.' );
		$pages = array_column( $parts, 0 );
		self::assertSame( range( 1, count( $parts ) ), array_column( $pages, 'seq' ) );
		self::assertCount( 1, array_unique( array_column( $pages, 'walk' ) ), 'One walk.' );
		self::assertSame( array_merge( array_fill( 0, count( $parts ) - 1, false ), array( true ) ), array_column( $pages, 'last' ) );
		$uploads_lines = array_values( array_filter( array_merge( ...$parts ), static fn( $l ) => 'files' === $l['env'] && 'uploads' === $l['ref'] ) );
		self::assertSame( array( $want ), $uploads_lines, 'Each file counted once, over parts.' );
	}

	public function testTheDropInsAndTheTablesAreThoseOfWordPress(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		self::assertSame( array_keys( _get_dropins() ), AB_MCP_Sync_Env::DROPINS, 'The drop-ins of a single site.' );
		global $wpdb;
		$tables = AB_MCP_Sync_Env::tables();
		self::assertIsArray( $tables );
		foreach ( $wpdb->tables( 'all', false ) as $core ) {
			self::assertNotContains( $core, $tables, $core . ' is of the core.' );
		}
		foreach ( AB_MCP_Sync_Schema::TABLES as $own ) {
			self::assertNotContains( $own, $tables, $own . ' is of the takeover.' );
		}
		self::sql( 'a', 'CREATE TABLE `{p}abenv_one` ( id INT )' );
		self::assertContains( 'abenv_one', AB_MCP_Sync_Env::tables(), 'A table of a plugin, without its prefix.' );
		self::assertNotContains( 'abenv_same', AB_MCP_Sync_Env::tables() );
		self::sql( 'b', 'CREATE TABLE `{p}abenv_other` ( id INT )' );
		self::assertNotContains( 'abenv_other', AB_MCP_Sync_Env::tables(), 'Not a table of another prefix.' );

		// The tables in parts of their room, one at least, each once.
		self::sql( 'a', 'CREATE TABLE `{p}abenv_two` ( id INT )' );
		self::sql( 'a', "INSERT INTO `{p}abenv_two` ( id ) VALUES ( 1 )" );
		$state = array(
			'after'    => null,
			'attached' => 0,
			'bytes'    => 0,
			'files'    => 0,
			'seq'      => 1,
			'skipped'  => 0,
			'step'     => 1,
			'walk'     => str_repeat( 'ab', 16 ),
		);
		$seen  = array();
		$parts = 0;
		do {
			$read = AB_MCP_Sync_Env::read( $state, 60, 2 );
			self::assertIsArray( $read );
			$lines = array_values( array_filter( $read['lines'], static fn( $l ) => 'table' === $l['env'] ) );
			self::assertLessThanOrEqual( 1, count( $lines ), 'Two lines a part: the page and one table.' );
			foreach ( $lines as $line ) {
				$seen[] = $line['ref'];
			}
			$state = $read['state'];
			++$parts;
		} while ( null !== $state && 1 === $state['step'] && $parts < 50 );
		self::assertSame( AB_MCP_Sync_Env::tables(), $seen, 'Each table once, in order.' );
		$two = array_values( array_filter( $read['lines'], static fn( $l ) => 'tables' === $l['env'] ) );
		self::assertSame( array( array( 'complete' => true, 'count' => count( $seen ), 'env' => 'tables', 'ref' => 'tables' ) ), $two, 'The list once it is read to its end.' );
	}

	public function testWithoutEnvironmentTheReportSaysItIsNotComparedWithTheWay(): void {
		self::pair();
		$answer = self::report( array( 'packages' => self::staged( 'inventory' ) ) );
		$env    = self::env( $answer['all'] );
		self::assertSame( array( 'environment:environment' ), array_keys( $env ) );
		self::assertSame( array( 'not_compared', 'none', null ), self::said( $env['environment:environment'] ) );
		self::assertStringContainsString( 'purpose=env', $env['environment:environment']['why'] );
		self::assertSame( 0, $answer['environment']['packages'] );
	}
}
