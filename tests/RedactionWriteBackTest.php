<?php
/**
 * A meta value read with [REDACTED] in it and written back keeps the stored
 * credential (AB_MCP_Redactor::value_back() in the three meta writers), and
 * the read-back of the write does not show it (saved_fields through the
 * redactor, AB_MCP_Redactor::PARTS).
 *
 * What must hold:
 *
 * - wp_get_post_meta shows {api_key: [REDACTED], color: red}; written back
 *   with color blue, the site stores {api_key: <the stored key>, color: blue}.
 * - saved_fields in the answer shows api_key as [REDACTED], and the answer
 *   names the path in redacted.
 * - The undo point holds the state before; wp_undo puts it back.
 * - A placeholder without a stored value at its path, and a text with the
 *   placeholder in it, are refused with ab_mcp_redacted_value; nothing is
 *   written.
 * - The same for user meta.
 *
 * The values below are made-up placeholders, not credentials.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Auth;
use AB_MCP_Redactor;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Content;
use AB_MCP_Tools_Meta_Auth;
use AB_MCP_Tools_Undo_Core;
use AB_MCP_Undo;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/undo-stubs.php';
require_once __DIR__ . '/widgets-meta-ops-stubs.php';
require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-post-status.php';
require_once __DIR__ . '/../includes/undo/class-undo.php';
require_once __DIR__ . '/../includes/undo/class-undo-restore.php';
require_once __DIR__ . '/../includes/tools/class-tools-meta-auth.php';
require_once __DIR__ . '/../includes/tools/class-tools-undo-core.php';

final class RedactionWriteBackTest extends TestCase {

	private const R = AB_MCP_Redactor::MARK;

	private string $uploads = '';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
		$this->uploads                     = sys_get_temp_dir() . '/ab-free-redaction-phpunit-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
		$GLOBALS['ab_test_upload_basedir'] = $this->uploads;
		$GLOBALS['wpdb']                   = new \AbTestUndoWpdb();
		$GLOBALS['ab_test_meta_writes']    = array();
		$GLOBALS['ab_test_meta_store']     = array();
		$GLOBALS['ab_test_meta_vetoes']    = array();
		ab_test_add_post( 5, array( 'post_title' => 'Page', 'post_status' => 'publish' ) );
		add_post_meta( 5, 'settings', array( 'api_key' => 'STORED-KEY', 'color' => 'red' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['ab_test_upload_basedir'] );
		$dir = $this->uploads . '/ab-mcp-undo';
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		@rmdir( $dir );
		@rmdir( $this->uploads );
	}

	/**
	 * A tool's answer as run_tool() hands it out.
	 *
	 * @param string $tool   Tool name.
	 * @param mixed  $result What the tool returned.
	 * @return mixed
	 */
	private function answer( string $tool, $result ) {
		return AB_MCP_Redactor::tool_result( $tool, array(), $result );
	}

	public function testAValueReadAndWrittenBackKeepsTheStoredCredential(): void {
		$read = $this->answer( 'wp_get_post_meta', AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 5, 'key' => 'settings' ) ) );
		self::assertSame( array( 'api_key' => self::R, 'color' => 'red' ), $read['value'] );

		$sent        = $read['value'];
		$sent['color'] = 'blue';
		$res         = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'settings', 'value' => $sent ) );

		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'api_key' => 'STORED-KEY', 'color' => 'blue' ), get_post_meta( 5, 'settings', true ), 'The credential stays, the colour changes.' );
		self::assertArrayNotHasKey( 'saved_differs', $res, 'Compared with the restored value, the write is as asked.' );

		$out = $this->answer( 'wp_update_post_meta', $res );
		self::assertSame( array( 'api_key' => self::R, 'color' => 'blue' ), ( (array) $out['saved_fields'] )['settings'] );
		self::assertSame( array( '/saved_fields/settings/api_key' ), $out['redacted'] );
		self::assertStringNotContainsString( 'STORED-KEY', (string) wp_json_encode( $out ) );

		$undo = AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) $res['undo'] ) );
		self::assertSame( 1, $undo['undone'], (string) wp_json_encode( $undo ) );
		self::assertSame( array( 'api_key' => 'STORED-KEY', 'color' => 'red' ), get_post_meta( 5, 'settings', true ), 'wp_undo puts the state before back.' );
	}

	public function testAPlaceholderWithoutAStoredValueIsRefused(): void {
		$before = $GLOBALS['ab_test_meta'];
		foreach (
			array(
				array( 'api_key' => 'NEW', 'webhook_secret' => self::R ),
				'{"api_key":"[REDACTED]"}',
				'my key is [REDACTED]',
				array( 'api_key' => 'a [REDACTED] b' ),
			) as $i => $value
		) {
			$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'settings', 'value' => $value ) );
			self::assertInstanceOf( \WP_Error::class, $res, (string) $i );
			self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code() );
			self::assertStringContainsString( 'Send the real value, or leave this field out', $res->get_error_message() );
		}
		self::assertSame( $before, $GLOBALS['ab_test_meta'], 'Nothing was written.' );
		$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'other', 'value' => array( 'api_key' => self::R ) ) );
		self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code(), 'A key that holds nothing yet has nothing to put back.' );
	}

	public function testTheMetaWritersAreLeftToRestoreAndTheirReadBackIsRedacted(): void {
		foreach ( array( 'wp_update_post_meta', 'wp_update_term_meta', 'wp_update_user_meta' ) as $tool ) {
			self::assertFalse( AB_MCP_Redactor::guards( $tool, array() ), $tool );
			self::assertSame( array( 'saved_fields' ), AB_MCP_Redactor::PARTS[ $tool ] );
		}
		$out = $this->answer( 'wp_update_media', array( 'updated' => true, 'saved_fields' => (object) array( 'alt' => 'x', 'token' => 'PLACEHOLDER' ) ) );
		self::assertSame( self::R, $out['saved_fields']->token );
		self::assertTrue( AB_MCP_Redactor::guards( 'wp_update_post', array() ), 'A post writer is still guarded centrally.' );
	}

	public function testUserMetaKeepsTheStoredCredentialToo(): void {
		$GLOBALS['ab_test_meta_store']['user'][7]['crm'] = array( array( 'token' => 'STORED-TOKEN', 'list' => 'news' ) );

		$res = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 7, 'key' => 'crm', 'value' => array( 'token' => self::R, 'list' => 'offers' ) ) );

		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'token' => 'STORED-TOKEN', 'list' => 'offers' ), get_user_meta( 7, 'crm', true ) );
		$res = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 7, 'key' => 'crm', 'value' => array( 'other_secret' => self::R ) ) );
		self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code() );
	}
}
