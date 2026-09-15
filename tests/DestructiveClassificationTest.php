<?php
/**
 * A tool that overwrites must not claim it only adds.
 *
 * MCP defines `destructiveHint: false` as "performs only additive updates",
 * and its default is true. Until 15.09.2026 this plugin derived the hint from
 * the `dangerous` flag, which answers a different question — whether a tool is
 * off until an admin switches it on. Only the four delete tools carried it, so
 * `wp_update_post`, `wp_update_media`, `wp_update_term` and
 * `wp_moderate_comment` announced "additive only" while overwriting existing
 * content. Measured against a running site, not guessed.
 *
 * Clients use the hint to decide whether to ask the user before a call, so the
 * false version is not a cosmetic slip: it invites an overwrite without a
 * prompt.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tool_Registry;

final class DestructiveClassificationTest extends TestCase {

	/** Tools that change or remove something that already exists. */
	private const OVERWRITES = array(
		'wp_update_post',
		'wp_update_media',
		'wp_update_term',
		'wp_moderate_comment',
		'wp_delete_post',
		'wp_delete_media',
		'wp_delete_term',
		'wp_delete_comment',
	);

	/** Tools that only ever add something new. */
	private const ADDS_ONLY = array(
		'wp_create_post',
		'wp_create_term',
		'wp_duplicate_post',
		'wp_reply_comment',
		'wp_upload_media',
		'wp_upload_media_from_url',
	);

	public function test_overwriting_tools_are_destructive(): void {
		foreach ( self::OVERWRITES as $name ) {
			$this->assertTrue(
				AB_MCP_Tool_Registry::is_destructive( $name, array() ),
				"$name changes existing content and must say destructiveHint: true"
			);
		}
	}

	public function test_adding_tools_are_not_destructive(): void {
		foreach ( self::ADDS_ONLY as $name ) {
			$this->assertFalse(
				AB_MCP_Tool_Registry::is_destructive( $name, array() ),
				"$name only adds, so destructiveHint: false is the honest answer"
			);
		}
	}

	public function test_reading_tools_are_never_destructive(): void {
		// The protocol says the hint is meaningful only when readOnlyHint is
		// false. A reading tool answering "destructive" would be nonsense.
		foreach ( array( 'wp_list_posts', 'wp_get_post', 'wp_search', 'wp_site_info' ) as $name ) {
			$this->assertFalse( AB_MCP_Tool_Registry::is_destructive( $name, array() ), "$name reads" );
		}
	}

	public function test_dangerous_no_longer_decides_the_hint(): void {
		// The two questions were the same until 15.09.2026. `dangerous` means
		// "off until an admin enables it" and must not move the hint either way.
		$this->assertTrue(
			AB_MCP_Tool_Registry::is_destructive( 'wp_update_post', array( 'dangerous' => false ) ),
			'an overwrite stays destructive even when it is enabled by default'
		);
		$this->assertFalse(
			AB_MCP_Tool_Registry::is_destructive( 'wp_get_user_meta', array( 'dangerous' => true ) ),
			'a reading tool stays non-destructive even when it is off by default'
		);
	}

	public function test_a_tool_may_state_it_itself(): void {
		$this->assertFalse(
			AB_MCP_Tool_Registry::is_destructive( 'wp_update_post', array( 'destructive' => false ) ),
			'an explicit declaration wins over the name'
		);
		$this->assertTrue(
			AB_MCP_Tool_Registry::is_destructive( 'wp_create_post', array( 'destructive' => true ) ),
			'and it wins in the other direction too'
		);
	}

	/**
	 * The object that actually travels to the client.
	 *
	 * Codex, 15.09.2026: testing the classification alone left the wiring
	 * uncovered — the annotation builder could go back to deriving the hint
	 * from `dangerous` without a test noticing. The builder is a pure function
	 * in the registry for exactly this reason, so what a client receives can be
	 * asserted whole.
	 */
	public function test_the_annotation_object_a_client_receives(): void {
		$this->assertSame(
			array(
				'title'           => 'Update Post',
				'readOnlyHint'    => false,
				'destructiveHint' => true,
				'idempotentHint'  => false,
				'openWorldHint'   => false,
			),
			AB_MCP_Tool_Registry::annotations( 'wp_update_post', array(), 'Update Post' )
		);
		$this->assertSame(
			array(
				'title'           => 'Create Post',
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => false,
				'openWorldHint'   => false,
			),
			AB_MCP_Tool_Registry::annotations( 'wp_create_post', array(), 'Create Post' )
		);
		$this->assertSame(
			array(
				'title'           => 'List Posts',
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			),
			AB_MCP_Tool_Registry::annotations( 'wp_list_posts', array(), 'List Posts' )
		);
	}

	/**
	 * Every writing tool in this plugin must land on one side or the other,
	 * and the default must be the protocol's: destructive unless it only adds.
	 */
	public function test_every_registered_writing_tool_is_classified(): void {
		$names = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) as $file ) {
			$source = file_get_contents( $file );
			if ( preg_match_all( '/->register\(\s*[\'"]([^\'"]+)[\'"]/', $source, $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		$this->assertGreaterThan( 30, count( $names ), 'the tool list was not found' );

		$writing = array_values(
			array_filter( $names, static fn( $n ) => ! AB_MCP_Tool_Registry::is_read_only( $n, array() ) )
		);
		$this->assertNotEmpty( $writing );

		$unclassified = array();
		foreach ( $writing as $n ) {
			$d = AB_MCP_Tool_Registry::is_destructive( $n, array() );
			if ( ! is_bool( $d ) ) {
				$unclassified[] = $n;
			}
		}
		$this->assertSame( array(), $unclassified );

		// And the split is the measured one: 8 overwrite, 6 only add.
		$destructive = array_values( array_filter( $writing, static fn( $n ) => AB_MCP_Tool_Registry::is_destructive( $n, array() ) ) );
		sort( $destructive );
		$expected = self::OVERWRITES;
		sort( $expected );
		$this->assertSame( $expected, $destructive, 'the set of overwriting tools changed — check the new tool' );
	}
}
