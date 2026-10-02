<?php
/**
 * A refusal names the way: what does not work, why, and what makes it work —
 * the switch and where it is, the right and who has it, the tool or
 * parameter to use instead. One case per kind of refusal; the texts
 * themselves live with the tools.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Auth;
use AB_MCP_Security;
use AB_MCP_Tools_Content;
use AB_MCP_Tools_Media;
use AB_MCP_Tools_Meta_Auth;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/../includes/tools/class-tools-meta-auth.php';

final class AnswerWayTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		self::scope( 'full' );
	}

	protected function tearDown(): void {
		self::scope( 'full' );
	}

	private static function scope( string $scope ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, $scope );
	}

	private static function message( $res ): string {
		self::assertInstanceOf( \WP_Error::class, $res );
		return (string) $res->get_error_message();
	}

	private static function allow( string ...$caps ): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => array() === $caps || in_array( $cap, $caps, true );
	}

	/* ------------------------------------------------- the policy gate */

	public function testAMissingCapabilityNamesTheAccountAndTheRole(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$msg = self::message( AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) ) );
		self::assertStringContainsString( '"edit_posts"', $msg );
		self::assertStringContainsString( 'Connect with an account whose role has it', $msg );
	}

	public function testADisabledToolNamesTheSwitch(): void {
		self::allow();
		update_option( 'ab_mcp_tool_state', array( 'wp_list_posts' => false ) );
		$res = AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) );
		self::assertSame( 'ab_mcp_tool_disabled', $res->get_error_code() );
		self::assertStringContainsString( 'Settings → AlphaBridge MCP → Capabilities', self::message( $res ) );
	}

	public function testANarrowAccessLevelNamesHowToWidenIt(): void {
		self::allow();
		self::scope( 'read' );
		$res = AB_MCP_Security::authorize( 'wp_update_post', array( 'capability' => 'edit_posts' ) );
		self::assertSame( 'ab_mcp_scope', $res->get_error_code() );
		self::assertStringContainsString( 'wider access level on the consent screen', self::message( $res ) );
		self::assertStringContainsString( 'Settings → AlphaBridge MCP', self::message( $res ) );
	}

	public function testTheRateLimitSaysHowManyAndHowLongToWait(): void {
		self::allow();
		update_option( 'ab_mcp_options', array( 'rate_limit_per_min' => 1 ) );
		self::assertTrue( AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) ) );
		$res = AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) );
		self::assertSame( 'ab_mcp_rate_limited', $res->get_error_code() );
		self::assertStringContainsString( 'at most 1 tool calls per minute', self::message( $res ) );
		self::assertStringContainsString( 'Wait until the next minute', self::message( $res ) );
	}

	/* ------------------------------------------------------ the tools */

	public function testAMissingPostNamesTheToolsThatFindIt(): void {
		self::allow();
		$msg = self::message( AB_MCP_Tools_Content::get_post( array( 'id' => 404 ) ) );
		self::assertStringContainsString( 'wp_list_posts', $msg );
	}

	public function testAnUnknownPostTypeNamesTheList(): void {
		self::allow();
		$msg = self::message( AB_MCP_Tools_Content::create_post( array( 'type' => 'nope', 'title' => 'X' ) ) );
		self::assertStringContainsString( 'wp_get_post_types', $msg );
	}

	public function testAnUnsupportedStatusNamesTheStatusesAndTheFilter(): void {
		self::allow();
		$msg = self::message( AB_MCP_Tools_Content::create_post( array( 'title' => 'X', 'status' => 'archived' ) ) );
		self::assertStringContainsString( 'draft, pending, publish, private and future', $msg );
		self::assertStringContainsString( 'ab_mcp_allowed_post_statuses', $msg );
	}

	public function testARefusedTrashSaysThePostIsUnchangedAndWhatRemains(): void {
		self::allow();
		ab_test_add_post( 9, array( 'post_status' => 'publish' ) );
		$GLOBALS['ab_test_trash_refused'] = true;
		$msg = self::message( AB_MCP_Tools_Content::delete_post( array( 'id' => 9 ) ) );
		self::assertStringContainsString( 'is unchanged', $msg );
		self::assertStringContainsString( 'force=true, which cannot be undone', $msg );
	}

	public function testAnAddressThatIsNotHttpNamesTheOtherUpload(): void {
		self::allow();
		$res = AB_MCP_Tools_Media::upload_from_url( array( 'url' => 'ftp://example.com/photo.jpg' ) );
		self::assertSame( 'ab_mcp_bad_url', $res->get_error_code() );
		self::assertStringContainsString( 'wp_upload_media', self::message( $res ) );
	}

	public function testAUserMetaKeyOutsideTheListNamesTheList(): void {
		self::allow();
		ab_test_add_user( 5 );
		$res = AB_MCP_Tools_Meta_Auth::get_user_meta_tool( array( 'user_id' => 5, 'key' => 'billing_token' ) );
		self::assertSame( 'ab_mcp_protected_meta', $res->get_error_code() );
		self::assertStringContainsString( 'first_name, last_name, nickname', self::message( $res ) );
		self::assertStringContainsString( 'leave key out', self::message( $res ) );
	}
}
