<?php
/**
 * A tool that can publish must say it reaches beyond the site.
 *
 * Until 7 October 2026 openWorldHint was true only for tools that talk to
 * other servers: downloads, installs, updates and Site Deploy. OpenAI's review
 * (developers.openai.com/plugins/deploy/app-review) also names write tools
 * that "publish content" or "post to public platforms". A site is a bounded
 * system, but what it publishes is public, so the eight tools that can make
 * content public now report true. Reading, drafts, new terms and deleting
 * stay inside the site.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tool_Registry;

final class OpenWorldClassificationTest extends TestCase {

	/** Writing tools that can make content public on the site. */
	private const PUBLISHING = array(
		'wp_create_post',
		'wp_update_post',
		'wp_reply_comment',
		'wp_moderate_comment',
		'wp_upload_media',
		'wp_upload_media_from_url',
		'wp_update_media',
		'wp_update_term',
	);

	/** Tools that talk to other servers, open world as before. */
	private const NETWORKED = array(
		'wp_upload_media_from_url',
		'wp_install_plugin',
		'wp_update_core',
		'wp_deploy_push_zip',
	);

	/** Tools that stay inside the site: reading, drafts, new terms, removal. */
	private const INSIDE = array(
		'wp_list_posts',
		'wp_get_post',
		'wp_search',
		'wp_duplicate_post',
		'wp_create_term',
		'wp_delete_post',
		'wp_delete_comment',
	);

	public function test_publishing_tools_are_open_world(): void {
		foreach ( self::PUBLISHING as $name ) {
			$this->assertTrue(
				AB_MCP_Tool_Registry::is_open_world( $name, array() ),
				"$name can make content public and must say openWorldHint: true"
			);
		}
	}

	public function test_networked_tools_stay_open_world(): void {
		foreach ( self::NETWORKED as $name ) {
			$this->assertTrue( AB_MCP_Tool_Registry::is_open_world( $name, array() ), "$name reaches another server" );
		}
	}

	public function test_publishing_tools_are_destructive_too(): void {
		// What is published cannot be taken back once it is out.
		foreach ( self::PUBLISHING as $name ) {
			$this->assertTrue( AB_MCP_Tool_Registry::is_destructive( $name, array() ), "$name publishes" );
		}
	}

	public function test_tools_inside_the_site_are_not_open_world(): void {
		foreach ( self::INSIDE as $name ) {
			$this->assertFalse( AB_MCP_Tool_Registry::is_open_world( $name, array() ), "$name stays inside the site" );
		}
	}

	/**
	 * A misspelt name in the list would leave the real tool at false without a
	 * test noticing, so every name must be a tool this plugin registers.
	 */
	public function test_every_publishing_tool_is_registered_here(): void {
		$names = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) as $file ) {
			if ( preg_match_all( '/->register\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents( $file ), $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		$this->assertGreaterThan( 30, count( $names ), 'the tool list was not found' );
		foreach ( self::PUBLISHING as $name ) {
			$this->assertContains( $name, $names, "$name is not a tool of this plugin" );
		}

		// And no other tool of this plugin is counted as publishing or networked.
		$open = array_values( array_filter( $names, static fn( $n ) => AB_MCP_Tool_Registry::is_open_world( $n, array() ) ) );
		sort( $open );
		$expected = self::PUBLISHING;
		sort( $expected );
		$this->assertSame( $expected, $open, 'the set of open-world tools changed — check the new tool' );
	}
}
