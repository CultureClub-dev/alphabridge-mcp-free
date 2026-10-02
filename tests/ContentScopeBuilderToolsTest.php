<?php
/**
 * The page-builder tools and the token scopes.
 *
 * A site handed over to a client who keeps the texts of its builder pages up
 * to date gets a content token. That token may read a page's outline
 * (wp_get_builder_layout, as a read token may) and change an element's
 * text, link or image through Pro's wp_update_builder_element; a read token
 * may not change anything. The decision for wp_update_builder_element holds
 * whatever capability Pro registers it with, so this test registers the tool
 * as the plan describes it (edit_posts, dangerous) and once with a
 * capability that is not content-level.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\TestCase;

final class ContentScopeBuilderToolsTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/** A tool definition as the registry stores it. */
	private function def( string $name, ?string $capability, bool $dangerous ): array {
		$r = new AB_MCP_Tool_Registry();
		$r->register(
			$name,
			array(
				'capability' => $capability,
				'dangerous'  => $dangerous,
				'callback'   => '__return_true',
			)
		);
		return $r->get( $name );
	}

	public function testTheOutlineToolRunsInEveryScope(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Builders::register( $r );
		$def = $r->get( 'wp_get_builder_layout' );
		self::assertIsArray( $def );
		foreach ( array( 'read', 'content', 'full', '' ) as $scope ) {
			self::assertTrue( AB_MCP_Tool_Registry::scope_allows( $scope, 'wp_get_builder_layout', $def ), 'Scope "' . $scope . '" may outline pages.' );
		}
	}

	public function testAContentTokenMayChangeBuilderTexts(): void {
		$def = $this->def( 'wp_update_builder_element', 'edit_posts', true );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_update_builder_element', $def ) );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'full', 'wp_update_builder_element', $def ) );
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_update_builder_element', $def ), 'A read token changes nothing.' );
		self::assertFalse( AB_MCP_Tool_Registry::is_read_only( 'wp_update_builder_element', $def ) );
	}

	public function testTheDecisionHoldsWhateverCapabilityTheToolCarries(): void {
		$def = $this->def( 'wp_update_builder_element', 'manage_options', true );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_update_builder_element', $def ), 'Named as a content tool, not merely let through by its capability.' );
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_update_builder_element', $def ) );
	}

	public function testOtherToolsStillGoByTheirCapability(): void {
		// The control: the name list widens the content scope by exactly one
		// tool. A writer with an administrative capability stays outside it,
		// one with an editorial capability inside, as before.
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_search_replace', $this->def( 'wp_search_replace', 'manage_options', true ) ) );
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_update_builder_elements', $this->def( 'wp_update_builder_elements', 'manage_options', true ) ), 'Only the exact name counts.' );
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_some_writer', $this->def( 'wp_some_writer', null, false ) ) );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_update_post', $this->def( 'wp_update_post', 'edit_posts', false ) ) );
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'unknown', 'wp_update_builder_element', $this->def( 'wp_update_builder_element', 'edit_posts', true ) ), 'An unknown scope still denies.' );
	}
}
