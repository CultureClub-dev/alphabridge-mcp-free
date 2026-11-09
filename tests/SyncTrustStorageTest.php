<?php
/**
 * How the key of a staging source is stored (Z8, decision 4; Codex round 1
 * of branch 5, findings A-1 and A-2).
 *
 * What must hold:
 *
 * - No report of a failed write shows the private key: while it is
 *   written, wpdb neither logs nor shows an error; afterwards both switches
 *   are as they were, and what wpdb keeps of that query (last_query,
 *   func_call, last_error, the entries of $EZSQL_ERROR and, with
 *   SAVEQUERIES, of queries) is gone, earlier entries kept. A database
 *   layer without wpdb's switches is written to as it is.
 * - A renewal reads back exactly the key it made. Where the options did not
 *   keep it (both writes refused, another source id, the old key under the
 *   new source id) the key is removed, nothing of the record is saved, the
 *   role is not confirmed, and the refusal names the options and the way;
 *   the form and the hub get it as such.
 *
 * Two WordPress sites with MariaDB show the same with a query the database
 * really refuses (tests/integration/SyncTrustTest.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_REST_Controller;
use AB_MCP_Settings;
use AB_MCP_Staging;
use AB_MCP_Sync_Key;
use AB_MCP_Tool_Registry;
use AbTestExit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

require_once __DIR__ . '/../includes/sync/class-sync-manifest.php';
require_once __DIR__ . '/SyncTrustSites.php';

/**
 * A wpdb that reports a failed query as wpdb::print_error() of WordPress
 * 7.1.3 does (a single site): into $EZSQL_ERROR in every case, then, unless
 * errors are suppressed, into the PHP error log, and, with show_errors, onto
 * the page; and that keeps the query as wpdb::query() and log_query() keep it.
 */
final class SyncTrustReportingWpdb {
	public $suppress_errors = false;
	public $show_errors     = true;
	public $last_query      = null;
	public $last_error      = '';
	public $func_call       = null;
	public $queries         = array();

	public function suppress_errors( $suppress = true ) {
		$errors                = $this->suppress_errors;
		$this->suppress_errors = (bool) $suppress;
		return $errors;
	}

	public function show_errors( $show = true ) {
		$errors            = $this->show_errors;
		$this->show_errors = $show;
		return $errors;
	}

	public function hide_errors() {
		$show              = $this->show_errors;
		$this->show_errors = false;
		return $show;
	}

	public function print_error( $str = '' ) {
		global $EZSQL_ERROR;
		$EZSQL_ERROR[] = array(
			'query'     => $this->last_query,
			'error_str' => $str,
		);
		if ( $this->suppress_errors ) {
			return false;
		}
		error_log( sprintf( 'WordPress database error %1$s for query %2$s made by %3$s', $str, $this->last_query, 'test' ) ); // phpcs:ignore
		if ( ! $this->show_errors ) {
			return false;
		}
		printf( '<div id="error"><p class="wpdberror"><strong>%s</strong> [%s]<br /><code>%s</code></p></div>', 'WordPress database error:', htmlspecialchars( $str, ENT_QUOTES ), htmlspecialchars( (string) $this->last_query, ENT_QUOTES ) ); // phpcs:ignore
		return null;
	}

	/** A query as wpdb::query() keeps it, with SAVEQUERIES. */
	public function run( string $query ): void {
		$this->func_call  = "\$db->query(\"$query\")";
		$this->last_query = $query;
		$this->queries[]  = array( $query, 0.0, 'test', 0.0, array() );
	}

	/** A query that fails, with the error of the server quoting it. */
	public function fail( string $query ): void {
		$this->run( $query );
		$this->last_error = "You have an error in your SQL syntax; check the manual that corresponds to your server version for the right syntax to use near '" . substr( $query, 12 ) . "' at line 1";
		$this->print_error( $this->last_error );
	}
}

final class SyncTrustStorageTest extends TestCase {

	use SyncTrustSites;

	/** @var mixed The $wpdb before the test. */
	private $db;

	/** @var mixed The $EZSQL_ERROR before the test. */
	private $ezsql;

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$this->db               = $GLOBALS['wpdb'] ?? null;
		$this->ezsql            = $GLOBALS['EZSQL_ERROR'] ?? null;
		ab_test_add_user( 3 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']        = $this->db;
		$GLOBALS['EZSQL_ERROR'] = $this->ezsql;
		$_POST                  = array();
		$_REQUEST               = array();
		unset( $GLOBALS['ab_test_home'] );
	}

	/** The record of the site running now, as stored. */
	private static function record_raw() {
		return AB_MCP_Settings::get( AB_MCP_Staging::KEY, array() );
	}

	/* ------------------------------------------------ no report shows it */

	/**
	 * Confirm the role staging site while $wpdb reports the write of the
	 * private key as $query_of( $value ) and returns what the write leaves.
	 *
	 * @return array{0:mixed,1:string,2:string,3:array} save()'s answer, the key written, the output, the switches during the write.
	 */
	private function confirm_while( SyncTrustReportingWpdb $db, bool $fails, string &$logged, string $option = AB_MCP_Sync_Key::OPTION_SECRET, string $field = 'secret_key' ): array {
		$GLOBALS['wpdb'] = $db;
		$secret          = '';
		$during          = array();
		add_filter(
			'pre_update_option_' . $option,
			static function ( $value, $old ) use ( $db, $fails, &$secret, &$during, $option, $field ) {
				$secret   = (string) $value[ $field ];
				$during[] = array( $db->suppress_errors, $db->show_errors );
				$query    = "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('" . $option . "', '" . serialize( $value ) . "', 'off')";
				if ( $fails ) {
					$db->fail( $query );
					return $old;
				}
				$db->run( $query );
				return $value;
			},
			10,
			2
		);
		$log = (string) tempnam( sys_get_temp_dir(), 'ab-trust-log-' );
		$ini = ini_set( 'error_log', $log );
		ob_start();
		try {
			$saved = AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		} finally {
			$out = (string) ob_get_clean();
			ini_set( 'error_log', (string) $ini );
			$logged = (string) file_get_contents( $log );
			unlink( $log );
		}
		self::assertNotSame( '', $secret, 'The private key was written.' );
		return array( $saved, $secret, $out, $during );
	}

	/** Nothing a report of the write keeps names the private key. */
	private static function assertNothingShows( string $secret, array $where ): void {
		foreach ( $where as $name => $text ) {
			self::assertStringNotContainsString( $secret, (string) $text, $name . ' shows the private key.' );
		}
	}

	/** @return array<string,array{0:string,1:string}> The secrets of a key: option, field. */
	public static function secrets(): array {
		return array(
			'the private key'        => array( AB_MCP_Sync_Key::OPTION_SECRET, 'secret_key' ),
			'the secret of the MACs' => array( AB_MCP_Sync_Key::OPTION_MAC, 'mac' ),
		);
	}

	#[DataProvider( 'secrets' )]
	public function testAFailedWriteOfThePrivateKeyIsReportedWithoutIt( string $option, string $field ): void {
		$this->on( 'staging' );
		$db                     = new SyncTrustReportingWpdb();
		$db->queries            = array( array( 'SELECT 1', 0.0, 'before', 0.0, array() ) );
		$GLOBALS['EZSQL_ERROR'] = array(
			array(
				'query'     => 'SELECT 2',
				'error_str' => 'earlier',
			),
		);
		$logged                 = '';
		list( $saved, $secret, $out, $during ) = $this->confirm_while( $db, true, $logged, $option, $field );

		self::assertSame( array( array( true, false ) ), $during, 'While the key was written, wpdb neither logged nor showed an error.' );
		self::assertFalse( $db->suppress_errors, 'Afterwards errors are reported again,' );
		self::assertTrue( $db->show_errors, 'and shown again, as before.' );
		self::assertNothingShows(
			$secret,
			array(
				'the page'    => $out,
				'the log'     => $logged,
				'EZSQL_ERROR' => serialize( $GLOBALS['EZSQL_ERROR'] ),
				'last_query'  => $db->last_query,
				'func_call'   => $db->func_call,
				'last_error'  => $db->last_error,
				'queries'     => serialize( $db->queries ),
				'the refusal' => $saved->get_error_message() . serialize( $saved->get_error_data() ),
			)
		);
		self::assertSame( '', $logged, 'Nothing was logged at all.' );
		self::assertSame( '', $out );
		self::assertSame(
			array(
				array(
					'query'     => 'SELECT 2',
					'error_str' => 'earlier',
				),
			),
			$GLOBALS['EZSQL_ERROR'],
			'The errors before stay.'
		);
		self::assertSame( array( array( 'SELECT 1', 0.0, 'before', 0.0, array() ) ), $db->queries, 'The queries before stay.' );
		self::assertSame( 'ab_mcp_sync_key_failed', $saved->get_error_code() );
		self::assertStringContainsString( $option, $saved->get_error_message(), 'The refusal names the option.' );
	}

	#[DataProvider( 'secrets' )]
	public function testAWriteThatWorksLeavesNoTraceOfItsQuery( string $option, string $field ): void {
		$this->on( 'staging' );
		$db     = new SyncTrustReportingWpdb();
		$logged = '';
		list( $saved, $secret ) = $this->confirm_while( $db, false, $logged, $option, $field );
		self::assertIsArray( $saved );
		self::assertNotNull( AB_MCP_Sync_Key::current() );
		self::assertNothingShows(
			$secret,
			array(
				'last_query' => $db->last_query,
				'func_call'  => $db->func_call,
				'queries'    => serialize( $db->queries ),
			)
		);
	}

	public function testADatabaseLayerWithoutTheSwitchesOfWpdbIsWrittenToAsItIs(): void {
		$this->on( 'staging' );
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' ) );
		self::assertNotNull( AB_MCP_Sync_Key::current() );
		$GLOBALS['wpdb'] = null;
		self::assertIsArray( AB_MCP_Sync_Key::renew() );
	}

	/* ----------------------------------------- the renewal reads back */

	/** @return array<string,array{0:callable}> */
	public static function keptWrongly(): array {
		$refuse = static fn( $value, $old ) => $old;
		return array(
			'both writes refused'               => array(
				static function () use ( $refuse ) {
					add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SECRET, $refuse, 10, 2 );
					add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SOURCE, $refuse, 10, 2 );
				},
			),
			'only the private key refused'      => array(
				static function () use ( $refuse ) {
					add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SECRET, $refuse, 10, 2 );
				},
			),
			'another source id kept'            => array(
				static function () {
					add_filter(
						'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SOURCE,
						static function ( $value ) {
							$value['source_id'] = str_repeat( 'f', 32 );
							return $value;
						}
					);
				},
			),
			'only the secret of the MACs refused' => array(
				static function () use ( $refuse ) {
					add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_MAC, $refuse, 10, 2 );
				},
			),
			'the secret of the MACs changed'    => array(
				static function () {
					add_filter(
						'pre_update_option_' . AB_MCP_Sync_Key::OPTION_MAC,
						static function ( $value ) {
							$value['mac'] = str_repeat( 'e', 64 );
							return $value;
						}
					);
				},
			),
			'the old key under the new source'  => array(
				static function () use ( $refuse ) {
					add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SECRET, $refuse, 10, 2 );
					add_filter(
						'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SOURCE,
						static function ( $value, $old ) {
							return array_merge( $old, array( 'source_id' => $value['source_id'] ) );
						},
						10,
						2
					);
				},
			),
		);
	}

	#[DataProvider( 'keptWrongly' )]
	public function testARenewalTheOptionsDoNotKeepConfirmsNothingAndLeavesNoKey( callable $refuse ): void {
		$this->on( 'staging' );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', 'https://www.example.com', 'production', 3, 'hub' ) );
		$old    = AB_MCP_Sync_Key::current();
		$record = self::record_raw();
		self::assertIsArray( $old );
		self::assertSame( 'hub', $record['role']['source'] );

		$refuse();
		$saved = AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		self::assertInstanceOf( \WP_Error::class, $saved );
		self::assertSame( 'ab_mcp_sync_key_failed', $saved->get_error_code() );
		self::assertSame( $record, self::record_raw(), 'Nothing of the record was saved: the role is not confirmed by the form.' );
		self::assertNull( AB_MCP_Sync_Key::current(), 'No key, not the old one either.' );
		self::assertFalse( get_option( AB_MCP_Sync_Key::OPTION_SECRET ) );
		self::assertFalse( get_option( AB_MCP_Sync_Key::OPTION_MAC ) );
		self::assertNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'x' ), 'Nothing is signed with a MAC either.' );
		self::assertInstanceOf( \WP_Error::class, AB_MCP_Sync_Key::sign( 'x', $old['key_id'] ), 'Nothing signs.' );
		self::assertNull( AB_MCP_Sync_Key::answer() );
		self::assertStringContainsString( 'AlphaBridge → Staging', $saved->get_error_message() );
	}

	public function testAProductionSiteConfirmsItsRoleWhateverTheKeyOptionsTake(): void {
		$this->on( 'live' );
		add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SECRET, static fn( $value, $old ) => $old, 10, 2 );
		self::assertIsArray( AB_MCP_Staging::save( 'production', 'https://staging.example.com', null, 3, 'form' ), 'No key is made for it, so no refused write stops it.' );
		self::assertSame( AB_MCP_Staging::ROLE_PRODUCTION, AB_MCP_Staging::role() );
	}

	public function testTheFormSaysTheRoleWasNotConfirmed(): void {
		$this->on( 'staging' );
		add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SECRET, static fn( $value, $old ) => $old, 10, 2 );
		$_POST    = array(
			'_wpnonce'     => 'nonce-ab_mcp_staging',
			'staging_role' => 'staging',
			'partner_url'  => 'https://www.example.com',
			'host'         => '',
		);
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin() )->handle_staging_record();
			self::fail( 'No redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'https://example.test/wp-admin/admin.php?page=alphabridge-staging&ab_notice=staging_trust_sync_key_failed#ab-staging-trust', str_replace( 'https://staging.example.com', 'https://example.test', $exit->detail ) );
		}
		self::assertSame( AB_MCP_Staging::ROLE_UNCONFIRMED, AB_MCP_Staging::role(), 'The role was not saved.' );
		self::assertArrayHasKey( 'staging_trust_sync_key_failed', AB_MCP_Admin::trust_notices() );
	}

	public function testTheHubIsToldTheRoleWasNotConfirmed(): void {
		$this->on( 'staging' );
		add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_SECRET, static fn( $value, $old ) => $old, 10, 2 );
		$GLOBALS['ab_test_routes'] = array();
		( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$token                  = \AB_MCP_Settings::add_token( 3, 'Hub', 'full', 0 );
		$request                = new WP_REST_Request();
		$request->set_method( 'POST' );
		$request->set_route( '/alphabridge/v1/staging-pair' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$request->set_json_params(
			array(
				'partner_url'  => 'https://www.example.com',
				'role'         => 'staging',
				'partner_role' => 'production',
				'source'       => 'hub',
			)
		);
		$GLOBALS['ab_test_current_user'] = 0;
		$response                        = ab_test_rest_dispatch( $request );
		self::assertSame( 'ab_mcp_sync_key_failed', $response->get_data()['code'] );
		self::assertSame( 500, $response->get_status() );
		self::assertSame( AB_MCP_Staging::ROLE_UNCONFIRMED, AB_MCP_Staging::role() );
	}
}
