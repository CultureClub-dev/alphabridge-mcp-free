<?php
/**
 * A connection acts as its mapped user. Whoever creates or rotates one for
 * somebody else hands out that person's authority, so the settings page's
 * `manage_options` is not enough: on a multisite network a site administrator
 * holds it and must still not mint a token for a network administrator.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Auth;
use AB_MCP_Settings;

final class TokenDelegationTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		ab_test_add_user( 9 );
		$GLOBALS['ab_test_current_user'] = 7;
	}

	/**
	 * Grant `edit_user` for exactly these user ids and nothing else.
	 *
	 * @param int[] $editable Users the current user may edit.
	 */
	private function mayEdit( array $editable ): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( $editable ): bool {
			return 'edit_user' === $cap && in_array( (int) ( $args[0] ?? 0 ), $editable, true );
		};
	}

	public function testOwnAccountIsAlwaysAllowed(): void {
		$this->mayEdit( array() );
		self::assertTrue( AB_MCP_Auth::may_issue_token_for( 7 ) );
	}

	public function testAnotherUserNeedsTheEditUserRight(): void {
		$this->mayEdit( array() );
		self::assertFalse( AB_MCP_Auth::may_issue_token_for( 9 ), 'manage_options alone does not delegate.' );

		$this->mayEdit( array( 9 ) );
		self::assertTrue( AB_MCP_Auth::may_issue_token_for( 9 ) );
	}

	public function testNobodyAndNoOneAreRefused(): void {
		$this->mayEdit( array( 9 ) );
		self::assertFalse( AB_MCP_Auth::may_issue_token_for( 0 ), 'No target user.' );

		$GLOBALS['ab_test_current_user'] = 0;
		self::assertFalse( AB_MCP_Auth::may_issue_token_for( 9 ), 'Not signed in.' );
		self::assertFalse( AB_MCP_Auth::may_issue_token_for( 0 ) );
	}

	public function testOnMultisiteOnlyASuperAdminMayActForASuperAdmin(): void {
		$GLOBALS['ab_test_multisite']    = true;
		$GLOBALS['ab_test_super_admins'] = array( 9 );
		// Even with edit_user widened (a user_has_cap filter, say) …
		$this->mayEdit( array( 9 ) );
		self::assertFalse( AB_MCP_Auth::may_issue_token_for( 9 ), 'A site admin cannot act for a network admin.' );

		$GLOBALS['ab_test_super_admins'] = array( 7, 9 );
		self::assertTrue( AB_MCP_Auth::may_issue_token_for( 9 ), 'A network admin can.' );
	}

	public function testASingleSiteIgnoresTheSuperAdminList(): void {
		$GLOBALS['ab_test_multisite']    = false;
		$GLOBALS['ab_test_super_admins'] = array( 9 );
		$this->mayEdit( array( 9 ) );
		self::assertTrue( AB_MCP_Auth::may_issue_token_for( 9 ) );
	}

	public function testRotationCanLookUpWhoseEntryItIs(): void {
		$plain = AB_MCP_Settings::add_token( 9, 'Theirs', 'full', 0 );
		$entry = AB_MCP_Settings::get_token_by_hash( hash( 'sha256', $plain ) );
		self::assertNotNull( $entry );
		self::assertSame( 9, $entry['user_id'] );
		self::assertNull( AB_MCP_Settings::get_token_by_hash( 'no-such-hash' ) );
	}
}
