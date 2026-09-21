<?php
/**
 * The two admin-ajax handlers that hand out a plaintext token — create and
 * rotate — must refuse to act for a user the caller may not edit, and must
 * keep working for the caller's own connections.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_Settings;
use AbTestJsonExit;

final class TokenAjaxTest extends TestCase {

	private AB_MCP_Admin $admin;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		ab_test_add_user( 9 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->admin                     = new AB_MCP_Admin();
		$_POST                           = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	/** An administrator of this site who may edit exactly these users. */
	private function adminWhoMayEdit( array $editable ): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( $editable ): bool {
			if ( 'manage_options' === $cap ) {
				return true;
			}
			return 'edit_user' === $cap && in_array( (int) ( $args[0] ?? 0 ), $editable, true );
		};
	}

	private function handle( callable $handler ): AbTestJsonExit {
		try {
			$handler();
		} catch ( AbTestJsonExit $exit ) {
			return $exit;
		}
		self::fail( 'The handler must end with a JSON response.' );
	}

	public function testCreatingForAnotherUserWithoutTheRightIs403AndStoresNothing(): void {
		$this->adminWhoMayEdit( array() );
		$_POST = array( 'user_id' => '9', 'scope' => 'full' );

		$exit = $this->handle( array( $this->admin, 'ajax_create_token' ) );

		self::assertSame( 403, $exit->status );
		self::assertSame( array(), AB_MCP_Settings::get_tokens(), 'No entry was written.' );
	}

	public function testCreatingForOneselfStillWorks(): void {
		$this->adminWhoMayEdit( array() );
		$_POST = array( 'user_id' => '7', 'scope' => 'content', 'label' => 'Mine' );

		$exit = $this->handle( array( $this->admin, 'ajax_create_token' ) );

		self::assertSame( 200, $exit->status );
		self::assertNotSame( '', $exit->payload['token'] );
		$tokens = AB_MCP_Settings::get_tokens();
		self::assertCount( 1, $tokens );
		self::assertSame( 7, $tokens[0]['user_id'] );
		self::assertSame( 'content', $tokens[0]['scope'] );
	}

	public function testCreatingForAUserOneMayEditWorks(): void {
		$this->adminWhoMayEdit( array( 9 ) );
		$_POST = array( 'user_id' => '9' );

		$exit = $this->handle( array( $this->admin, 'ajax_create_token' ) );

		self::assertSame( 200, $exit->status );
		self::assertSame( 9, AB_MCP_Settings::get_tokens()[0]['user_id'] );
	}

	public function testRotatingAnotherUsersConnectionWithoutTheRightIs403AndChangesNothing(): void {
		$this->adminWhoMayEdit( array() );
		$plain = AB_MCP_Settings::add_token( 9, 'Theirs', 'full', 0 );
		$hash  = hash( 'sha256', $plain );
		$_POST = array( 'hash' => $hash );

		$exit = $this->handle( array( $this->admin, 'ajax_rotate_token' ) );

		self::assertSame( 403, $exit->status );
		self::assertSame( $hash, AB_MCP_Settings::get_tokens()[0]['hash'], 'The old secret still stands.' );
		self::assertNotFalse( \AB_MCP_Auth::verify_token( $plain ) );
	}

	public function testRotatingOnesOwnConnectionWorks(): void {
		$this->adminWhoMayEdit( array() );
		$plain = AB_MCP_Settings::add_token( 7, 'Mine', 'read', 0 );
		$_POST = array( 'hash' => hash( 'sha256', $plain ) );

		$exit = $this->handle( array( $this->admin, 'ajax_rotate_token' ) );

		self::assertSame( 200, $exit->status );
		self::assertFalse( \AB_MCP_Auth::verify_token( $plain ), 'The old secret is dead.' );
		$entry = AB_MCP_Settings::get_tokens()[0];
		self::assertSame( 7, $entry['user_id'] );
		self::assertSame( 'read', $entry['scope'] );
		self::assertSame( 'Mine', $entry['label'] );
	}

	public function testRotatingAnUnknownHashIs404(): void {
		$this->adminWhoMayEdit( array( 9 ) );
		$_POST = array( 'hash' => 'no-such-hash' );

		self::assertSame( 404, $this->handle( array( $this->admin, 'ajax_rotate_token' ) )->status );
	}
	public function testRotatingAConnectionOfAUserOneMayEditWorks(): void {
		$this->adminWhoMayEdit( array( 9 ) );
		$plain = AB_MCP_Settings::add_token( 9, 'Theirs', 'full', 0 );
		$_POST = array( 'hash' => hash( 'sha256', $plain ) );

		$exit = $this->handle( array( $this->admin, 'ajax_rotate_token' ) );

		self::assertSame( 200, $exit->status );
		self::assertFalse( \AB_MCP_Auth::verify_token( $plain ) );
		self::assertSame( 9, AB_MCP_Settings::get_tokens()[0]['user_id'] );
	}
}
