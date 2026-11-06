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
		remove_all_filters( 'ab_mcp_redact_keys' );
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

	/**
	 * B packs one purpose (a series) and A takes it over: the package ids;
	 * $answers gets the answers of both sites; $with more of each export
	 * step on B (redact_keys, env_clock: tests/integration/bin/sync-site.php).
	 */
	private static function staged( string $purpose, ?int $objects = null, array &$answers = array(), array $with = array() ): array {
		$ids    = array();
		$cursor = null;
		do {
			$step = $with + array(
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
			$package   = self::b( array( $step ) )['package'];
			$cursor    = $package['cursor'];
			$answers[] = $package;
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
				$answers[] = $state;
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
		self::assertSame( array( 'same_value', 'none', null ), self::said( $env['core:locale'] ), 'The language shown, never equal.' );

		self::assertSame( array( 'equal', 'none', null ), self::said( $env['mu_plugin:ab-it-env-same.php'] ), 'Read by its SHA-256: equal.' );
		self::assertSame( array( 'differs', 'locked', 'Z8b' ), self::said( $env['mu_plugin:ab-it-env-differs.php'] ) );
		self::assertSame( array( 'contents' ), $env['mu_plugin:ab-it-env-differs.php']['differs'] );
		self::assertSame( array( 'differs', 'host', null ), self::said( $env['dropin:db-error.php'] ), 'A drop-in with another hash: changed at the host.' );
		self::assertSame( array( 'equal', 'host', null ), self::said( $env['dropin:php-error.php'] ), 'Equal by its SHA-256, changed at the host.' );
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

		self::assertSame( array( 'same_value', 'host', null ), self::said( $env['host:php'] ), 'Group U: shown, never equal, changed at the host.' );
		self::assertSame( array( PHP_VERSION, PHP_VERSION ), array( $env['host:php']['staging']['value'], $env['host:php']['live']['value'] ) );
		self::assertSame( array( 'same_value', 'host', null ), self::said( $env['host:database'] ) );
		self::assertSame( array( 'same_value', 'host', null ), self::said( $env['host:constant:WP_DEBUG'] ) );
		self::assertSame( array( 'differs', 'host', null ), self::said( $env['host:constant:WP_DEBUG_LOG'] ), 'A path of each site, compared by its hash.' );
		self::assertSame( array( 'differs', 'host', null ), self::said( $env['host:mu_loader'] ) );
		self::assertSame( array( 'present', 'absent' ), array( $env['host:mu_loader']['staging']['value'], $env['host:mu_loader']['live']['value'] ) );
		self::assertArrayNotHasKey( 'mu_plugin:' . AB_MCP_Sync_Env::LOADER, $env, 'The loader is group U.' );
		self::assertSame( array( 'same_value', 'host', null ), self::said( $env['host:htaccess'] ), 'No .htaccess on either site.' );
		self::assertSame( 'absent', $env['host:htaccess']['live']['value'] );
		self::assertSame( 'not shown', $env['host:constant:WP_DEBUG_LOG']['live']['value'] );

		foreach ( $env as $key => $entry ) {
			if ( in_array( $entry['subtype'], array( 'plugin', 'theme', 'table', 'files' ), true ) || 'core:wordpress' === $key ) {
				self::assertNotSame( 'equal', $entry['result'], $key . ': counted or summed is never equal.' );
			}
			if ( in_array( $entry['subtype'], array( 'host', 'core' ), true ) ) {
				self::assertNotSame( 'equal', $entry['result'], $key . ': a value is shown, never equal.' );
			}
			if ( in_array( $entry['subtype'], array( 'host', 'dropin' ), true ) ) {
				self::assertSame( 'host', $entry['action'], $key . ': group U is changed at the host.' );
			}
			self::assertArrayNotHasKey( 'held', $entry, $key . ': an ordinary name, shown as it is.' );
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

	/* ------------------------------------------- names a credential holds */

	/** Every file of the protected store of both sites, unpacked where it is a gzip. */
	private static function store_texts(): array {
		$root  = (string) AB_MCP_IT_Sites::state()['root'] . '/ab-mcp-store';
		$texts = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$bytes                   = (string) file_get_contents( (string) $file );
			$plain                   = "\x1f\x8b" === substr( $bytes, 0, 2 ) ? gzdecode( $bytes ) : $bytes;
			$texts[ (string) $file ] = false === $plain ? $bytes : $plain;
		}
		return $texts;
	}

	public function testANameWithACredentialIsInNoAnswerPackageCursorOrFileOfTheStore(): void {
		self::pair();
		$secret = 'EXAMPLE_SECRET';
		foreach ( array( 'a', 'b' ) as $site ) {
			self::write( $site, 'mu-plugins/password=' . $secret . '.php', "<?php\n// The same on both.\n" );
			self::write( $site, 'plugins/ab-it-env-same/ab-it-env-same.php', self::plugin( 'Env same', '1.0' ) );
			// A table before and after the one whose name holds it, so that a
			// part of a table ends at it.
			self::sql( $site, 'CREATE TABLE `{p}abenv_a` ( id INT )' );
			self::sql( $site, 'CREATE TABLE `{p}abenv_token=' . $secret . '` ( id INT )' );
			self::sql( $site, 'CREATE TABLE `{p}abenv_zz` ( id INT )' );
		}
		wp_cache_delete( 'plugins', 'plugins' );
		$answers = array();
		$ids     = self::staged( 'env', 2, $answers );
		self::assertGreaterThan( 3, count( $ids ), 'A table a package: a cursor at each table.' );
		$cursors = array_column( array_filter( $answers, static fn( $a ) => isset( $a['cursor'] ) ), 'cursor' );
		self::assertNotEmpty( array_filter( $cursors, static fn( $c ) => array( hash( 'sha256', 'abenv_token=' . $secret ) ) === $c['inner']['after'] ), 'A cursor at that table, by its SHA-256.' );
		self::assertNotEmpty( array_filter( $cursors, static fn( $c ) => array( hash( 'sha256', 'abenv_a' ) ) === $c['inner']['after'] ), 'An ordinary table by the SHA-256 of its name as well.' );
		$exported = array_values( array_filter( $answers, static fn( $a ) => isset( $a['credentials'] ) ) );
		self::assertSame( 1, $exported[0]['credentials']['held'], 'The first package: the must-use plugin held.' );

		AB_MCP_Sync_Report::$env_lines = 2;
		$answer                        = self::report(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => $ids,
			)
		);
		$env  = self::env( $answer['all'] );
		$file = 'mu_plugin:' . AB_MCP_Sync_Env::hashed( 'password=' . $secret . '.php' );
		self::assertSame( array( 'equal', 'none', null ), self::said( $env[ $file ] ), 'Held on both sites, compared by the SHA-256 of its file still.' );
		self::assertSame( 'credential_field', $env[ $file ]['held'] );
		$table = 'table:' . AB_MCP_Sync_Env::hashed( 'abenv_token=' . $secret );
		self::assertSame( array( 'counted', 'locked', 'Z8d' ), self::said( $env[ $table ] ) );
		self::assertSame( 'credential_field', $env[ $table ]['held'] );
		self::assertArrayHasKey( 'plugin:ab-it-env-same/ab-it-env-same.php', $env, 'An ordinary name as it is.' );
		self::assertArrayHasKey( 'table:abenv_zz', $env );
		self::assertArrayNotHasKey( 'held', $env['table:abenv_zz'] );

		// The files of this site, in parts that end in a folder whose name holds it.
		for ( $i = 0; $i < 150; $i++ ) {
			self::write( 'a', sprintf( 'uploads/api_key=%s/%03d.txt', $secret, $i ), 'x' );
		}
		$whole = AB_MCP_Sync_Env::read( null, 60 );
		self::assertIsArray( $whole );
		$now                    = 0.0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$now ) {
			return ++$now;
		};
		$state  = null;
		$states = array();
		$lines  = array();
		do {
			$read = AB_MCP_Sync_Env::read( $state, 2 );
			self::assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
			$lines    = array_merge( $lines, $read['lines'] );
			$state    = null === $read['state'] ? null : json_decode( \AB_MCP_Sync_Canon::encode( $read['state'] ), true );
			$states[] = $state;
		} while ( null !== $state && count( $states ) < 200 );
		self::assertNull( $state );
		$inside = array_filter( $states, static fn( $s ) => is_array( $s ) && 'uploads' === AB_MCP_Sync_Env::STEPS[ $s['step'] ] && is_array( $s['after'] ) && hash( 'sha256', 'api_key=' . $secret ) === $s['after'][0] );
		self::assertNotEmpty( $inside, 'Parts that stopped in that folder, kept by its SHA-256.' );
		$uploads = static fn( array $all ) => array_values( array_filter( $all, static fn( $l ) => 'files' === $l['env'] && 'uploads' === $l['ref'] ) );
		self::assertSame( $uploads( $whole['lines'] ), $uploads( $lines ), 'Each file counted once, over parts.' );
		self::assertSame( 150, $uploads( $lines )[0]['files'] );

		$texts = array( 'the report' => (string) json_encode( $answer ), 'the answers' => (string) json_encode( $answers ), 'the states' => (string) json_encode( $states ), 'the lines' => (string) json_encode( $lines ) ) + self::store_texts();
		self::assertGreaterThan( 10, count( $texts ) );
		self::assertNotEmpty( array_filter( $texts, static fn( $t ) => false !== strpos( $t, AB_MCP_Sync_Env::hashed( 'password=' . $secret . '.php' ) ) ), 'The name is in the store, by its SHA-256.' );
		foreach ( $texts as $where => $text ) {
			self::assertStringNotContainsString( $secret, $text, "The name in $where." );
		}
	}

	public function testANameWithACredentialAfterAColonOrBelowAFolderIsInNoAnswerCursorPackageOrFileOfTheStore(): void {
		self::pair();
		$secret = 'EXAMPLE_SECRET';
		foreach ( array( 'a', 'b' ) as $site ) {
			self::write( $site, 'mu-plugins/password:' . $secret . '.php', "<?php\n// The same on both.\n" );
			self::write( $site, 'mu-plugins/reset-password.php', "<?php\n// An ordinary name.\n" );
			// Files below a folder whose name holds it, more than one part counts.
			for ( $i = 0; $i < 300; $i++ ) {
				self::write( $site, sprintf( 'uploads/password=/%s/%03d.txt', $secret, $i ), 'x' );
			}
			self::sql( $site, 'CREATE TABLE `{p}abenv_a` ( id INT )' );
			self::sql( $site, 'CREATE TABLE `{p}abenv_password:' . $secret . '` ( id INT )' );
			self::sql( $site, 'CREATE TABLE `{p}abenv_woocommerce_api_keys` ( id INT )' );
			self::sql( $site, 'CREATE TABLE `{p}abenv_zz` ( id INT )' );
		}
		$answers = array();
		$ids     = self::staged( 'env', 2, $answers, array( 'env_clock' => 15 ) );
		$cursors = array_column( array_filter( $answers, static fn( $a ) => isset( $a['cursor'] ) ), 'cursor' );
		self::assertNotEmpty( array_filter( $cursors, static fn( $c ) => array( hash( 'sha256', 'abenv_password:' . $secret ) ) === $c['inner']['after'] ), 'A cursor at that table, by the SHA-256 of its name.' );
		self::assertNotEmpty( array_filter( $cursors, static fn( $c ) => 'uploads' === AB_MCP_Sync_Env::STEPS[ $c['inner']['step'] ] && 3 === count( (array) $c['inner']['after'] ) ), 'A cursor below that folder, by SHA-256 only.' );

		AB_MCP_Sync_Report::$env_lines = 2;
		$inventory                     = self::staged( 'inventory' );
		$now                           = 0.0;
		AB_MCP_Sync_Env::$clock        = static function () use ( &$now ) {
			$now += 15;
			return $now;
		};
		$answer = self::report(
			array(
				'packages'    => $inventory,
				'environment' => $ids,
			)
		);
		AB_MCP_Sync_Env::$clock = null;
		$env  = self::env( $answer['all'] );
		$file = 'mu_plugin:' . AB_MCP_Sync_Env::hashed( 'password:' . $secret . '.php' );
		self::assertSame( array( 'equal', 'none', null, 'credential_field' ), array_merge( self::said( $env[ $file ] ), array( $env[ $file ]['held'] ) ), 'Held on both sites, compared by the SHA-256 of its file still.' );
		$table = 'table:' . AB_MCP_Sync_Env::hashed( 'abenv_password:' . $secret );
		self::assertSame( array( 'counted', 'locked', 'Z8d', 'credential_field' ), array_merge( self::said( $env[ $table ] ), array( $env[ $table ]['held'] ) ) );
		self::assertSame( array( 'counted', 'locked', 'Z8b' ), self::said( $env['files:uploads'] ), 'The uploads counted to their end over parts on both sites.' );
		foreach ( array( 'mu_plugin:reset-password.php', 'table:abenv_woocommerce_api_keys', 'table:abenv_zz', 'table:abenv_a' ) as $plain ) {
			self::assertArrayHasKey( $plain, $env, 'An ordinary name as it is.' );
			self::assertArrayNotHasKey( 'held', $env[ $plain ] );
		}
		$parts = array_filter( self::store_texts(), static fn( $where ) => false !== strpos( $where, '/reports/' ), ARRAY_FILTER_USE_KEY );
		self::assertGreaterThan( 3, count( $parts ), 'The environment of this site in parts of the store.' );

		$texts = array( 'the report' => (string) json_encode( $answer ), 'the answers' => (string) json_encode( $answers ) ) + self::store_texts();
		foreach ( $texts as $where => $text ) {
			self::assertStringNotContainsString( $secret, $text, "The name in $where." );
		}
	}

	public function testANameOnlyTheRulesOfTheStagingSiteHoldIsHeldInThePartsOfThisSiteAsWell(): void {
		self::pair();
		$name  = 'vault=EXAMPLE_SECRET.php';
		$table = 'vault=EXAMPLE_SECRET';
		foreach ( array( 'a', 'b' ) as $site ) {
			self::write( $site, 'mu-plugins/' . $name, "<?php\n// The same on both.\n" );
			self::sql( $site, 'CREATE TABLE `{p}' . $table . '` ( id INT )' );
		}
		// The rules of the staging site only: there both names are held, here not.
		$answers = array();
		$ids     = self::staged( 'env', null, $answers, array( 'redact_keys' => array( 'vault' ) ) );
		AB_MCP_Sync_Report::$env_lines = 2;
		$answer                        = self::report(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => $ids,
			)
		);
		$env = self::env( $answer['all'] );
		$key = 'mu_plugin:' . AB_MCP_Sync_Env::hashed( $name );
		self::assertSame( array( 'equal', 'none', null, 'credential_field' ), array_merge( self::said( $env[ $key ] ), array( $env[ $key ]['held'] ) ), 'One entry, by its SHA-256.' );
		$key = 'table:' . AB_MCP_Sync_Env::hashed( $table );
		self::assertSame( array( 'counted', 'credential_field' ), array( $env[ $key ]['result'], $env[ $key ]['held'] ) );
		$parts = array_filter( self::store_texts(), static fn( $where ) => false !== strpos( $where, '/reports/' ), ARRAY_FILTER_USE_KEY );
		self::assertNotEmpty( $parts );
		self::assertNotEmpty( array_filter( $parts, static fn( $t ) => false !== strpos( $t, AB_MCP_Sync_Env::hashed( $name ) ) ), 'The name of this site in its parts, by its SHA-256.' );
		$texts = array( 'the report' => (string) json_encode( $answer ), 'the answers' => (string) json_encode( $answers ) ) + self::store_texts();
		foreach ( $texts as $where => $text ) {
			self::assertStringNotContainsString( 'EXAMPLE_SECRET', $text, "The name in $where." );
		}
	}

	public function testANameOnlyTheRulesOfThisSiteHoldIsHeldByTheImport(): void {
		self::pair();
		$name = 'vault=EXAMPLE_VAULT.php';
		foreach ( array( 'a', 'b' ) as $site ) {
			self::write( $site, 'mu-plugins/' . $name, "<?php\n// The same on both.\n" );
		}
		self::write( 'b', 'mu-plugins/vault=EXAMPLE_ONLY.php', "<?php\n" );
		// The rules of this site only: the staging site packs both names as they are.
		add_filter( 'ab_mcp_redact_keys', static fn( array $keys ): array => array_merge( $keys, array( 'vault' ) ) );
		$answers = array();
		$ids     = self::staged( 'env', null, $answers );
		self::assertSame( 2, end( $answers )['held'], 'The import holds both by the rules of this site.' );
		$answer = self::report(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => $ids,
			)
		);
		$env = self::env( $answer['all'] );
		$key = 'mu_plugin:' . AB_MCP_Sync_Env::hashed( $name );
		self::assertSame( array( 'equal', 'none', null, 'credential_field' ), array_merge( self::said( $env[ $key ] ), array( $env[ $key ]['held'] ) ), 'One entry: this site holds its own as well.' );
		$only = 'mu_plugin:' . AB_MCP_Sync_Env::hashed( 'vault=EXAMPLE_ONLY.php' );
		self::assertSame( array( 'only_staging', 'credential_field' ), array( $env[ $only ]['result'], $env[ $only ]['held'] ) );
		self::assertStringNotContainsString( 'EXAMPLE_', (string) json_encode( $answer ) );
		// The package itself holds the names as the staging site wrote and
		// signed them (out there, in here): the report and the parts of this
		// site do not.
		$reports = array_filter( self::store_texts(), static fn( $where ) => false !== strpos( $where, '/reports/' ), ARRAY_FILTER_USE_KEY );
		self::assertNotEmpty( $reports );
		foreach ( $reports as $where => $text ) {
			self::assertStringNotContainsString( 'EXAMPLE_', $text, "A name in $where." );
		}
		foreach ( array( 'R&D notes.pdf', 'reset-password/reset-password.php', 'woocommerce_api_keys', '&amp;', 'wp-oauth-server/wp-oauth-server.php', 'password-protected/password-protected.php' ) as $plain ) {
			self::assertNull( AB_MCP_Sync_Env::held( $plain ), $plain . ': an ordinary name, as it is.' );
		}
	}

	public function testAPlaceKeptByItsSha256ThatIsGoneLeavesItsRootNotCountedToItsEnd(): void {
		self::write( 'a', 'uploads/token=gone/a.txt', 'x' );
		$state = array(
			'after'    => AB_MCP_Sync_Env::place( 'token=gone/a.txt' ),
			'attached' => 0,
			'bytes'    => 1,
			'files'    => 1,
			'seq'      => 2,
			'skipped'  => 0,
			'step'     => array_search( 'uploads', AB_MCP_Sync_Env::STEPS, true ),
			'walk'     => str_repeat( 'ab', 16 ),
		);
		$read  = AB_MCP_Sync_Env::read( $state, 60 );
		self::assertIsArray( $read );
		self::assertSame( array( 'complete' => true, 'files' => 1 ), array_intersect_key( $read['lines'][1], array( 'complete' => 0, 'files' => 0 ) ), 'It is there: the count goes on after it.' );
		rename( self::content( 'a' ) . '/uploads/token=gone', self::content( 'a' ) . '/uploads/token=moved' );
		$read = AB_MCP_Sync_Env::read( $state, 60 );
		self::assertIsArray( $read );
		self::assertSame( array( false, 'moved' ), array( $read['lines'][1]['complete'], $read['lines'][1]['why'] ), 'Gone since: not counted to its end (fail closed).' );
		// An ordinary name the same: no name in the place, so nothing says where to go on.
		self::write( 'a', 'uploads/zz/b.txt', 'y' );
		$ordinary = array( 'after' => AB_MCP_Sync_Env::place( 'zz/b.txt' ) ) + $state;
		$read     = AB_MCP_Sync_Env::read( $ordinary, 60 );
		self::assertIsArray( $read );
		self::assertTrue( $read['lines'][1]['complete'], 'There: counted to its end.' );
		unlink( self::content( 'a' ) . '/uploads/zz/b.txt' );
		$read = AB_MCP_Sync_Env::read( $ordinary, 60 );
		self::assertIsArray( $read );
		self::assertSame( array( false, 'moved' ), array( $read['lines'][1]['complete'], $read['lines'][1]['why'] ), 'An ordinary file gone since as well.' );
		$tables = array( 'after' => array( hash( 'sha256', 'token=gone' ) ), 'step' => 1 ) + $state;
		$read   = AB_MCP_Sync_Env::read( $tables, 60, 2 );
		self::assertIsArray( $read );
		self::assertSame( array( 'complete' => false, 'count' => 0, 'env' => 'tables', 'ref' => 'tables' ), $read['lines'][1], 'A table kept by its SHA-256 that is gone: the list not read whole.' );
	}

	public function testALaterCallOfAnImportNamesNoOtherPurpose(): void {
		self::pair();
		$package = self::b( array( array( 'op' => 'export', 'purpose' => 'env', 'as' => 'p' ) ) )['p'];
		$begin   = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => 'env',
		);
		self::assertIsArray( AB_MCP_Sync_Import::call( $begin ) );
		$other = 'ab_mcp_sync_import_purpose_other';
		self::assertStringContainsString( 'purpose=env', self::refused( AB_MCP_Sync_Import::call( array( 'purpose' => 'inventory' ) + $begin ), $other ) );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'], 'purpose' => 'inventory' ) ), $other );
		$data = self::b( array( array( 'op' => 'export_chunk', 'package_id' => $package['package_id'], 'chunk' => 0, 'as' => 'c' ) ) )['c']['data'];
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'], 'chunk' => 0, 'data' => $data, 'purpose' => 'inventory' ) ), $other );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'] ) );
		self::assertIsArray( $state );
		self::assertSame( range( 0, $package['chunks'] - 1 ), $state['missing'], 'Nothing taken under another purpose.' );
		self::assertSame( 'env', $state['purpose'] );
		foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
			$data  = self::b( array( array( 'op' => 'export_chunk', 'package_id' => $package['package_id'], 'chunk' => $i, 'as' => 'c' ) ) )['c']['data'];
			$state = AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'], 'chunk' => $i, 'data' => $data, 'purpose' => 'env' ) );
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		}
		self::assertTrue( $state['complete'], 'The purpose it was begun for goes on.' );
		self::assertIsArray( AB_MCP_Sync_Import::taken( $package['package_id'], 'env' ) );
		self::refused( AB_MCP_Sync_Import::taken( $package['package_id'] ), 'ab_mcp_sync_import_purpose_held' );
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
		self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $inventory, 'environment' => array_map( static fn( $i ) => sprintf( '%032x', $i ), range( 1, AB_MCP_Sync_Report::MAX_ENVIRONMENT + 1 ) ) ) ), 'ab_mcp_sync_report_environment' );

		// A cursor of the environment goes on with the environment only.
		self::gone_on_b( $another );
		$first = self::b( array( array( 'op' => 'export', 'purpose' => 'env', 'objects' => 6, 'as' => 'p' ) ) )['p'];
		self::assertSame( 'env', $first['cursor']['kind'] );
		$wrong = self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'cursor' => $first['cursor'], 'as' => 'p', 'expect' => 'error' ) ) )['p'];
		self::assertSame( 'ab_mcp_sync_cursor_invalid', $wrong['code'] );
		self::gone_on_b( array( $first['package_id'] ) );
		$inventory_first = self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'objects' => 2, 'as' => 'p' ) ) )['p'];
		self::assertNotNull( $inventory_first['cursor'] );
		$wrong = self::b( array( array( 'op' => 'export', 'purpose' => 'env', 'cursor' => $inventory_first['cursor'], 'as' => 'p', 'expect' => 'error' ) ) )['p'];
		self::assertSame( 'ab_mcp_sync_cursor_invalid', $wrong['code'], 'And a cursor of the inventory with the inventory only.' );
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

		// A part whose time ran out between two steps begins no further one.
		$state = array(
			'after'    => null,
			'attached' => 0,
			'bytes'    => 0,
			'files'    => 0,
			'seq'      => 1,
			'skipped'  => 0,
			'step'     => array_search( 'languages', AB_MCP_Sync_Env::STEPS, true ),
			'walk'     => str_repeat( 'ab', 16 ),
		);
		$read  = AB_MCP_Sync_Env::read( $state, 0.5 );
		self::assertIsArray( $read );
		self::assertSame( array( 'page', 'files' ), array_column( $read['lines'], 'env' ), 'The language files, then the part ends.' );
		self::assertSame( 'fonts', AB_MCP_Sync_Env::STEPS[ $read['state']['step'] ] );
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
