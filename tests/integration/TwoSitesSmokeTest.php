<?php
/**
 * The smoke test of the integration environment: two WordPress 7.1.3 sites
 * in one MariaDB database under two prefixes, each running the plugin of
 * the working tree, site A in this process and both over HTTP.
 *
 * What the later Z8 cases build on, each shown once: a write on site A
 * through WordPress lands in A's row and in no row of B, and A's own server
 * reads it; site B answers its administrator over REST and the plugin's
 * MCP endpoint, and refuses a request without credentials; the production
 * way to a partner (AB_MCP_Outbound::get()) refuses the local site B, the
 * test fetcher reaches it and nothing else; and reset() puts a site back.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_Outbound;
use AB_MCP_Test_Sync_Fetcher;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class TwoSitesSmokeTest extends TestCase {

	public static function setUpBeforeClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
	}

	public function testTwoSitesRunThePluginOnTheirOwnRowsAndAnswerOverHttp(): void {
		// Two installations, one database: this process is site A, and both
		// sites run the plugin of this working tree, activated by WordPress.
		$repo = realpath( dirname( __DIR__, 2 ) );
		self::assertSame( AB_MCP_IT_Sites::prefix( 'a' ), $GLOBALS['wpdb']->prefix );
		self::assertNotSame( AB_MCP_IT_Sites::prefix( 'a' ), AB_MCP_IT_Sites::prefix( 'b' ) );
		self::assertSame( '7.1.3', get_bloginfo( 'version' ) );
		foreach ( AB_MCP_IT_Sites::SITES as $key ) {
			$active = maybe_unserialize( AB_MCP_IT_Sites::option_row( $key, 'active_plugins' )['option_value'] );
			self::assertContains( AB_MCP_IT_Sites::PLUGIN, $active, "The plugin is active on site $key." );
			self::assertSame( $repo, realpath( AB_MCP_IT_Sites::docroot( $key ) . '/wp-content/plugins/alphabridge-mcp' ), "Site $key runs the working tree." );
			self::assertSame( AB_MCP_IT_Sites::url( $key ), AB_MCP_IT_Sites::option_row( $key, 'siteurl' )['option_value'] );
		}

		// A write on site A through WordPress: in A's row, read past every
		// cache, not in B's, and A's own server reads the same row.
		$title = 'Smoke ' . bin2hex( random_bytes( 6 ) );
		self::assertTrue( update_option( 'blogname', $title ) );
		self::assertSame( $title, AB_MCP_IT_Sites::option_row( 'a', 'blogname' )['option_value'] );
		self::assertSame( 'Integration site B', AB_MCP_IT_Sites::option_row( 'b', 'blogname' )['option_value'] );
		self::assertSame( array(), AB_MCP_IT_Sites::rows( 'a', 'options', array( 'option_name' => 'BLOGNAME' ) ), 'The rows are compared byte for byte.' );
		$settings = AB_MCP_IT_Sites::request( 'a', 'GET', '/wp/v2/settings' );
		self::assertSame( 200, $settings['status'], $settings['raw'] );
		self::assertSame( $title, $settings['body']['title'] );

		// Site B as its administrator, over REST and the MCP endpoint; the
		// same request without credentials is refused, so it is the
		// credentials that open it.
		$me = AB_MCP_IT_Sites::request( 'b', 'GET', '/wp/v2/users/me', array( 'context' => 'edit' ) );
		self::assertSame( 200, $me['status'], $me['raw'] );
		self::assertContains( 'administrator', $me['body']['roles'] );
		self::assertSame( 401, AB_MCP_IT_Sites::request( 'b', 'GET', '/wp/v2/users/me', array( 'context' => 'edit' ), null, 'none' )['status'] );
		$tools = AB_MCP_IT_Sites::mcp( 'b', 'tools/list' );
		self::assertSame( 200, $tools['status'], $tools['raw'] );
		self::assertContains( 'wp_site_info', array_column( $tools['body']['result']['tools'], 'name' ), $tools['raw'] );
		self::assertSame( 401, AB_MCP_IT_Sites::request( 'b', 'POST', AB_MCP_IT_Sites::MCP_ROUTE, array(), array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ), 'none' )['status'] );

		// The way to a partner. Production asks only public hosts, so it
		// refuses site B on 127.0.0.1 before any request; the test fetcher
		// reaches B, and only the two sites.
		$index   = AB_MCP_IT_Sites::url( 'b', '/?rest_route=/' );
		$refused = AB_MCP_Outbound::get( $index, array() );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_outbound_local', $refused->get_error_code() );
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		$answer  = $fetcher->get( $index, array( 'timeout' => 30 ) );
		self::assertIsArray( $answer );
		self::assertSame( 200, wp_remote_retrieve_response_code( $answer ) );
		self::assertSame( AB_MCP_IT_Sites::url( 'b' ), json_decode( wp_remote_retrieve_body( $answer ), true )['home'] );
		$foreign = $fetcher->get( 'http://127.0.0.1:9/' );
		self::assertInstanceOf( WP_Error::class, $foreign );
		self::assertSame( 'ab_mcp_test_fetcher_foreign', $foreign->get_error_code() );
		self::assertSame( array( $index, 'http://127.0.0.1:9/' ), array_column( $fetcher->calls, 'url' ) );

		// reset() puts site A back, in the table and in this process.
		AB_MCP_IT_Sites::reset( 'a' );
		self::assertSame( 'Integration site A', AB_MCP_IT_Sites::option_row( 'a', 'blogname' )['option_value'] );
		self::assertSame( 'Integration site A', get_option( 'blogname' ) );
	}
}
