<?php
/**
 * wp_site_pairs as far as a test without a database shows it (Z8 decisions
 * 13 and 15; branch 6, part 1):
 *
 * - registered like the other tools of the takeover: manage_options, the
 *   access level Full, not held by «Staging first» (CARRY_OVER), and
 *   destructive (confirm aligns existing pairs anew, discard ends them);
 * - the confirmation in the same call: `when` a strict false only for
 *   propose and list, so they run at once; confirm, discard, no action and
 *   any other ask for the token, whose state is the plan;
 * - refused with the way: no action or an unknown one, a network, a site
 *   without a confirmed key of a staging site, a proposal id that is none,
 *   a selection that is not one, discard without pair ids.
 *
 * The proposal, the confirmation with its token, discard and list over two
 * WordPress sites: tests/integration/SyncProposalTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Confirm_Token;
use AB_MCP_Staging_Gate;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Pairs_Tool;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-staging-gate.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';
require_once __DIR__ . '/../includes/sync/class-sync-pairs-tool.php';
require_once __DIR__ . '/SyncTrustSites.php';

final class SyncPairsToolTest extends TestCase {

	use SyncTrustSites;

	protected function setUp(): void {
		ab_test_reset();
		AB_MCP_Sync_Kinds::reset();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_home'], $GLOBALS['wpdb'] );
	}

	private static function def(): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		$def = $r->get( 'wp_site_pairs' );
		self::assertNotNull( $def );
		return $def;
	}

	private static function refused( $result, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_message();
	}

	public function testItIsRegisteredLikeTheOtherToolsOfTheTakeover(): void {
		$def = self::def();
		self::assertSame( 'manage_options', $def['capability'] );
		self::assertFalse( AB_MCP_Tool_Registry::is_read_only( 'wp_site_pairs', $def ), 'Writing: the access level Full.' );
		self::assertTrue( AB_MCP_Tool_Registry::is_destructive( 'wp_site_pairs', $def ) );
		self::assertTrue( $def['destructive'], 'Said in its definition, not only by its name.' );
		self::assertFalse( AB_MCP_Tool_Registry::is_open_world( 'wp_site_pairs', $def ) );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'full', 'wp_site_pairs', $def ) );
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_site_pairs', $def ) );
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_site_pairs', $def ) );
		self::assertFalse( AB_MCP_Staging_Gate::concerns( 'wp_site_pairs', $def ), 'The carrying over (CARRY_OVER).' );
		self::assertSame( array( 'action' ), $def['inputSchema']['required'] );
		self::assertSame( AB_MCP_Sync_Pairs_Tool::ACTIONS, $def['inputSchema']['properties']['action']['enum'] );
		self::assertSame( AB_MCP_Sync_Pairs_Tool::BY_CATEGORY, $def['inputSchema']['properties']['categories']['items']['enum'] );
		self::assertArrayHasKey( AB_MCP_Confirm_Token::PARAM, $def['inputSchema']['properties'] );
		foreach ( array( 'action=propose', 'action=confirm', 'action=discard', 'action=list', 'held', 'live_only only where', 'at most 500', 'no content of either site changes', 'multisite', 'action=confirm and action=discard run in two steps' ) as $says ) {
			self::assertStringContainsString( $says, $def['description'] );
		}
		self::assertStringNotContainsString( 'Runs in two steps', $def['description'], 'propose and list run at once.' );
	}

	public function testOnlyProposeAndListRunWithoutTheToken(): void {
		foreach ( array( 'propose', 'list' ) as $action ) {
			self::assertFalse( AB_MCP_Tools_Staging::pairs_when( array( 'action' => $action ) ), $action );
		}
		foreach ( array( array( 'action' => 'confirm' ), array( 'action' => 'discard' ), array(), array( 'action' => 'other' ), array( 'action' => array( 'propose' ) ) ) as $a ) {
			self::assertTrue( AB_MCP_Tools_Staging::pairs_when( $a ), (string) json_encode( $a ) );
		}
		$def = self::def();
		self::assertSame( array( AB_MCP_Tools_Staging::class, 'pairs_when' ), $def['confirm']['when'] );
		self::assertSame( array( AB_MCP_Tools_Staging::class, 'pairs_state' ), $def['confirm']['state'] );
		self::assertSame( array( AB_MCP_Tools_Staging::class, 'pairs_preview' ), $def['confirm']['preview'] );
	}

	public function testTheGateLetsProposeAndListRunAndAsksConfirmForItsState(): void {
		$def = self::def();
		self::assertTrue( AB_MCP_Confirm_Token::gate( 'wp_site_pairs', $def, array( 'action' => 'list' ) ) );
		self::assertTrue( AB_MCP_Confirm_Token::gate( 'wp_site_pairs', $def, array( 'action' => 'propose', 'packages' => array( 'x' ) ) ) );
		// confirm asks its state, which refuses here: no key of a staging site.
		self::refused( AB_MCP_Confirm_Token::gate( 'wp_site_pairs', $def, array( 'action' => 'confirm', 'proposal_id' => str_repeat( 'a', 32 ), 'select' => array( 1 ) ) ), 'ab_mcp_sync_pairs_no_anchor' );
		self::refused( AB_MCP_Confirm_Token::gate( 'wp_site_pairs', $def, array( 'action' => 'discard', 'pair_ids' => array( 1 ) ) ), 'ab_mcp_sync_pairs_no_anchor' );
		self::refused( AB_MCP_Confirm_Token::gate( 'wp_site_pairs', $def, array() ), 'ab_mcp_sync_pairs_action' );
	}

	public function testEveryActionNeedsAConfirmedKeyAndNoNetwork(): void {
		foreach ( array( 'propose', 'list' ) as $action ) {
			$msg = self::refused( AB_MCP_Tools_Staging::site_pairs( array( 'action' => $action ) ), 'ab_mcp_sync_pairs_no_anchor' );
			self::assertStringContainsString( 'AlphaBridge → Staging', $msg );
		}
		foreach ( array( 'confirm', 'discard' ) as $action ) {
			self::refused( AB_MCP_Tools_Staging::pairs_state( array( 'action' => $action ) ), 'ab_mcp_sync_pairs_no_anchor' );
		}
		$GLOBALS['ab_test_multisite'] = true;
		foreach ( array( 'propose', 'list' ) as $action ) {
			self::refused( AB_MCP_Tools_Staging::site_pairs( array( 'action' => $action ) ), 'ab_mcp_sync_multisite' );
		}
		self::refused( AB_MCP_Tools_Staging::pairs_preview( array( 'action' => 'confirm' ) ), 'ab_mcp_sync_multisite' );
	}

	public function testWithoutAnActionNothingRuns(): void {
		foreach ( array( array(), array( 'action' => 'other' ), array( 'action' => 3 ) ) as $a ) {
			self::assertStringContainsString( 'propose, confirm, discard or list', self::refused( AB_MCP_Tools_Staging::site_pairs( $a ), 'ab_mcp_sync_pairs_action' ) );
			self::refused( AB_MCP_Tools_Staging::pairs_state( $a ), 'ab_mcp_sync_pairs_action' );
		}
	}

	public function testAProposalIdThatIsNoneAndDiscardWithoutPairsAreRefused(): void {
		$this->paired();
		$this->anchored();
		foreach ( array( null, 'x', str_repeat( 'g', 32 ), str_repeat( 'A', 32 ) ) as $id ) {
			self::refused( AB_MCP_Tools_Staging::pairs_state( array( 'action' => 'confirm', 'proposal_id' => $id, 'select' => array( 1 ) ) ), 'ab_mcp_sync_pairs_unknown' );
			if ( null !== $id ) {
				self::refused( AB_MCP_Tools_Staging::site_pairs( array( 'action' => 'propose', 'proposal_id' => $id ) ), 'ab_mcp_sync_pairs_unknown' );
			}
		}
		foreach ( array( array(), array( 'pair_ids' => array() ), array( 'pair_ids' => array( 0 ) ), array( 'pair_ids' => array( 1, 1 ) ), array( 'pair_ids' => 'x' ), array( 'pair_ids' => array( 'a' => 1 ) ), array( 'pair_ids' => range( 1, 501 ) ) ) as $a ) {
			self::assertStringContainsString( 'wp_site_pairs action=list', self::refused( AB_MCP_Tools_Staging::pairs_state( array( 'action' => 'discard' ) + $a ), 'ab_mcp_sync_pairs_selection' ), (string) json_encode( $a ) );
		}
		self::refused( AB_MCP_Tools_Staging::site_pairs( array( 'action' => 'propose', 'packages' => array( 'x' ) ) ), 'ab_mcp_sync_import_package' );
		foreach ( array( array(), array( 'packages' => array() ), array( 'packages' => 'x' ), array( 'packages' => array( 'a', 'a' ) ), array( 'packages' => array( 'k' => 'a' ) ), array( 'packages' => array_map( 'strval', range( 1, 101 ) ) ) ) as $a ) {
			self::refused( AB_MCP_Tools_Staging::site_pairs( array( 'action' => 'propose' ) + $a ), 'ab_mcp_sync_pairs_packages' );
		}
	}

	public function testListTakesOnlyAStateOfAPair(): void {
		$this->paired();
		$this->anchored();
		self::assertStringContainsString( 'active, deleted, unclear, residual', self::refused( AB_MCP_Tools_Staging::site_pairs( array( 'action' => 'list', 'state' => 'gone' ) ), 'ab_mcp_sync_pairs_selection' ) );
	}
}
