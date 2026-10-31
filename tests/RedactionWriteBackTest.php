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
 * - A placeholder without a stored value at its path, JSON or serialized
 *   data with the placeholder in it, and a key name=[REDACTED] without the
 *   one stored key it stands for are refused with ab_mcp_redacted_value;
 *   nothing is written.
 * - A key read as name=[REDACTED] gets the stored key back; an object the
 *   reader showed by its JSON form (ArrayObject) is written back by it; a
 *   stored object put back keeps the backslashes of its texts.
 * - Texts people wrote are written as sent: one that only mentions or
 *   quotes [REDACTED], and one under a content field that is exactly
 *   [REDACTED]; a content field the reader redacts (a filter's name) gets
 *   the stored value back.
 * - A stored object that cannot be copied to be slashed is not written
 *   back: refused, nothing written, its backslashes kept.
 * - The same for user meta; wp_update_widget's answer shows its settings
 *   redacted, as wp_get_widgets does, and wp_update_widget puts the stored
 *   value back for [REDACTED] and keeps one a nested setting leaves out.
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
use AB_MCP_Tools_Widgets;
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
require_once __DIR__ . '/../includes/tools/class-tools-widgets.php';

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
				array( 'api_key' => 'NEW', 'config' => '{"token":"[REDACTED]"}' ),
				array( 'password=' . self::R => 'on' ),
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
		// As the core registers them: its own handlers.
		$r = new AB_MCP_Tool_Registry();
		foreach ( array( AB_MCP_Tools_Content::class => 'content', AB_MCP_Tools_Meta_Auth::class => 'meta-auth', AB_MCP_Tools_Widgets::class => 'widgets' ) as $class => $group ) {
			$r->set_current_group( $group, $group );
			$class::register( $r );
		}
		foreach ( array( 'wp_update_post_meta', 'wp_update_term_meta', 'wp_update_user_meta', 'wp_update_widget' ) as $tool ) {
			self::assertFalse( AB_MCP_Redactor::guards( $tool, $r->get( $tool ) ), $tool );
			self::assertTrue( AB_MCP_Redactor::guards( $tool, array() ), $tool . ' without the core\'s handler is guarded.' );
			self::assertContains( 'saved_fields', AB_MCP_Redactor::PARTS[ $tool ] );
		}
		$out = $this->answer( 'wp_update_media', array( 'updated' => true, 'saved_fields' => (object) array( 'alt' => 'x', 'token' => 'PLACEHOLDER' ) ) );
		self::assertSame( self::R, $out['saved_fields']->token );
		self::assertTrue( AB_MCP_Redactor::guards( 'wp_update_post', array() ), 'A post writer is still guarded centrally.' );
	}

	public function testARestoredObjectKeepsTheBackslashesOfItsTexts(): void {
		// Codex 4 #1: wp_slash() leaves an object as it is, the unslashing
		// of update_metadata() (map_deep()) goes into it.
		$GLOBALS['ab_test_meta'][5]['conn'] = array( serialize( array( 'credentials' => (object) array( 'password' => 'a\\b' ), 'color' => 'red' ) ) );
		$read = $this->answer( 'wp_get_post_meta', AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 5, 'key' => 'conn' ) ) );
		self::assertSame( array( 'credentials' => self::R, 'color' => 'red' ), $read['value'] );

		$sent          = $read['value'];
		$sent['color'] = 'blue';
		$res           = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'conn', 'value' => $sent ) );

		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		$stored = get_post_meta( 5, 'conn', true );
		self::assertSame( 'a\\b', $stored['credentials']->password, 'The backslash stays.' );
		self::assertSame( 'blue', $stored['color'] );
		self::assertArrayNotHasKey( 'saved_differs', $res, (string) wp_json_encode( $res ) );
	}

	public function testAKeyReadWithThePlaceholderGetsTheStoredKeyBack(): void {
		// Codex 4 #2: a key name=value is read as name=[REDACTED].
		add_post_meta( 5, 'flags', array( 'password=STORED-PW' => 'enabled', 'color' => 'red' ) );
		$read = $this->answer( 'wp_get_post_meta', AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 5, 'key' => 'flags' ) ) );
		self::assertSame( array( 'password=' . self::R => 'enabled', 'color' => 'red' ), $read['value'] );

		$sent          = $read['value'];
		$sent['color'] = 'blue';
		$res           = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'flags', 'value' => $sent ) );

		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'password=STORED-PW' => 'enabled', 'color' => 'blue' ), get_post_meta( 5, 'flags', true ), 'The stored key stays, in its place.' );

		// No stored key it stands for, or more than one: refused.
		add_post_meta( 5, 'twice', array( 'password=A-ONE' => 'x', 'password=A-TWO' => 'y' ) );
		$before = $GLOBALS['ab_test_meta'];
		$res    = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'flags', 'value' => array( 'token=' . self::R => 'on', 'color' => 'red' ) ) );
		self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code() );
		$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'twice', 'value' => array( 'password=' . self::R => 'y' ) ) );
		self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code() );
		self::assertSame( $before, $GLOBALS['ab_test_meta'], 'Nothing was written.' );
	}

	public function testAnObjectIsWrittenBackByTheFormTheReaderShows(): void {
		// Codex 4 #7: the reader shows an ArrayObject by its JSON form.
		$GLOBALS['ab_test_meta'][5]['box'] = array( serialize( new \ArrayObject( array( 'api_key' => 'STORED-AO', 'color' => 'red' ) ) ) );
		$read = $this->answer( 'wp_get_post_meta', AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 5, 'key' => 'box' ) ) );
		self::assertSame( '{"api_key":"[REDACTED]","color":"red"}', wp_json_encode( $read['value'] ) );

		$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'box', 'value' => array( 'api_key' => self::R, 'color' => 'blue' ) ) );

		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'api_key' => 'STORED-AO', 'color' => 'blue' ), get_post_meta( 5, 'box', true ) );
	}

	public function testTextsPeopleWroteAreWrittenAsSent(): void {
		// Codex 4 #8: as on main, a text that mentions the placeholder, and
		// a text under a field people write that is exactly the placeholder.
		add_post_meta( 5, 'block', array( 'content' => 'Old text', 'api_key' => 'STORED-B', 'note' => 'old' ) );

		$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'block', 'value' => array( 'content' => 'Name: [REDACTED]', 'api_key' => self::R, 'note' => 'Name: [REDACTED]' ) ) );
		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'content' => 'Name: [REDACTED]', 'api_key' => 'STORED-B', 'note' => 'Name: [REDACTED]' ), get_post_meta( 5, 'block', true ) );

		$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'block', 'value' => array( 'content' => self::R, 'api_key' => self::R ) ) );
		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'content' => self::R, 'api_key' => 'STORED-B' ), get_post_meta( 5, 'block', true ), 'The text sent, not the one before.' );

		$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'motto', 'value' => 'Name: [REDACTED]' ) );
		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( 'Name: [REDACTED]', get_post_meta( 5, 'motto', true ) );
	}

	public function testTheWidgetWriterAnswersWithTheSettingsRedacted(): void {
		// Codex 4 #3: wp_update_widget answers with the whole stored instance.
		$GLOBALS['wp_widget_factory'] = (object) array( 'widgets' => array( new \WP_Widget( 'text' ) ) );
		update_option( 'widget_text', array( 4 => array( 'title' => 'feed', 'accessToken' => 'STORED-AT', 'config' => '{"api_key":"STORED-CFG"}' ), '_multiwidget' => 1 ) );
		try {
			$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-4', 'settings' => array( 'title' => 'feed 2' ) ) );
		} finally {
			unset( $GLOBALS['wp_widget_factory'] );
		}
		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );

		$out = $this->answer( 'wp_update_widget', $res );

		self::assertStringNotContainsString( 'STORED', (string) wp_json_encode( $out ) );
		self::assertSame( 'feed 2', $out['settings']['title'] );
		self::assertSame( self::R, $out['settings']['accessToken'] );
		self::assertSame( '{"api_key":"[REDACTED]"}', $out['settings']['config'] );
		self::assertSame( array( '/settings/accessToken', '/settings/config/api_key' ), $out['redacted'] );
		self::assertSame( 'STORED-AT', get_option( 'widget_text' )[4]['accessToken'], 'Stored as it was.' );
	}

	public function testAKeyTheReaderRedactsIsRestoredThoughItIsAContentField(): void {
		// Codex 5 #1: the reader redacts before it spares a content field;
		// writing back goes the same way.
		add_filter( 'ab_mcp_redact_keys', static fn() => array( 'description' ) );
		add_post_meta( 5, 'card', array( 'description' => 'STORED-D', 'color' => 'red' ) );
		$read = $this->answer( 'wp_get_post_meta', AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 5, 'key' => 'card' ) ) );
		self::assertSame( array( 'description' => self::R, 'color' => 'red' ), $read['value'] );

		$sent          = $read['value'];
		$sent['color'] = 'blue';
		$res           = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'card', 'value' => $sent ) );

		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'description' => 'STORED-D', 'color' => 'blue' ), get_post_meta( 5, 'card', true ) );
		self::assertSame( '/value/description', AB_MCP_Redactor::placeholder_in( array( 'value' => array( 'description' => self::R ) ) ), 'The central guard refuses it.' );
	}

	public function testAStoredObjectThatCannotBeSlashedIsNotWrittenBack(): void {
		// Codex 5 #2: no copy, so no slashes; written, WordPress would strip them.
		$GLOBALS['ab_test_meta'][5]['conn'] = array( serialize( array( 'credentials' => new RedactionNoCopyProbe(), 'color' => 'red' ) ) );
		$before                             = $GLOBALS['ab_test_meta'];

		$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'conn', 'value' => array( 'credentials' => self::R, 'color' => 'blue' ) ) );

		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code() );
		self::assertStringContainsString( 'cannot be written back unchanged', $res->get_error_message() );
		self::assertStringContainsString( 'Send the real value in place of [REDACTED]', $res->get_error_message() );
		self::assertSame( $before, $GLOBALS['ab_test_meta'], 'Nothing was written.' );
		self::assertSame( 'a\\b', get_post_meta( 5, 'conn', true )['credentials']->password );
	}

	public function testATextThatOnlyQuotesThePlaceholderIsWrittenAsSent(): void {
		// Codex 5 #3: the placeholder is carried by what decodes as the reader decodes.
		foreach ( array( 'Name: "[REDACTED]"', 'He wrote "[REDACTED]" and s:10:"[REDACTED]";', array( 'note' => '"[REDACTED]" in quotes' ) ) as $i => $value ) {
			$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'note', 'value' => $value ) );
			self::assertIsArray( $res, $i . ': ' . ( is_object( $res ) ? $res->get_error_message() : '' ) );
			self::assertSame( $value, get_post_meta( 5, 'note', true ) );
		}
		self::assertNull( AB_MCP_Redactor::placeholder_in( array( 'label' => 'Name: "[REDACTED]"' ) ) );
	}

	public function testAWidgetReadWithThePlaceholderIsWrittenBackWithTheStoredValue(): void {
		// Codex 6 #1: wp_get_widgets leaves out what the meta guard names and
		// redacts the rest; wp_update_widget takes both back as the meta
		// writers do.
		$GLOBALS['wp_widget_factory']     = (object) array( 'widgets' => array( new \WP_Widget( 'text' ) ) );
		$GLOBALS['wp_registered_widgets'] = array( 'text-4' => array( 'name' => 'Text' ) );
		update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array(), 'sidebar-1' => array( 'text-4' ) ) );
		update_option( 'widget_text', array( 4 => array( 'title' => 'feed', 'feed' => array( 'accessToken' => 'STORED-AT', 'count' => 5 ) ), '_multiwidget' => 1 ) );
		try {
			$read = $this->answer( 'wp_get_widgets', AB_MCP_Tools_Widgets::get_widgets( array() ) );
			self::assertSame( array( 'accessToken' => self::R, 'count' => 5 ), $read['widgets'][0]['settings']['feed'] );
			self::assertFalse( AB_MCP_Redactor::guards( 'wp_update_widget', array( 'callback' => array( AB_MCP_Tools_Widgets::class, 'update_widget' ) ) ), 'Left to the writer, as the meta writers are.' );

			$sent                  = $read['widgets'][0]['settings'];
			$sent['feed']['count'] = 10;
			$res                   = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-4', 'settings' => $sent ) );
			self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
			self::assertSame( array( 'accessToken' => 'STORED-AT', 'count' => 10 ), get_option( 'widget_text' )[4]['feed'], 'The stored token stays.' );
			self::assertArrayNotHasKey( 'saved_differs', $res, (string) wp_json_encode( $res ) );
			self::assertStringNotContainsString( 'STORED', (string) wp_json_encode( $this->answer( 'wp_update_widget', $res ) ) );

			// Left out by the client: kept, as for the keys wp_get_widgets leaves out.
			$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-4', 'settings' => array( 'feed' => array( 'count' => 12 ) ) ) );
			self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
			self::assertSame( 'STORED-AT', get_option( 'widget_text' )[4]['feed']['accessToken'] );
			self::assertSame( 12, get_option( 'widget_text' )[4]['feed']['count'] );

			// Nothing stored there to put back: refused, nothing written.
			$before = get_option( 'widget_text' );
			$res    = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-4', 'settings' => array( 'feed' => array( 'apiToken' => self::R, 'count' => 1 ) ) ) );
			self::assertInstanceOf( \WP_Error::class, $res );
			self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code() );
			self::assertSame( $before, get_option( 'widget_text' ) );
			// The undo point of such a write: tests/standalone/undo-terms-widgets-test.php, F25a–d.
		} finally {
			unset( $GLOBALS['wp_widget_factory'], $GLOBALS['wp_registered_widgets'] );
		}
	}

	/**
	 * A tools/call of a writing tool registered with this callback.
	 *
	 * @param string              $tool     Tool name.
	 * @param callable            $callback Its handler.
	 * @param array<string,mixed> $args     Arguments.
	 * @return array The result of the call.
	 */
	private function call( string $tool, $callback, array $args ): array {
		update_option( 'ab_mcp_options', array( 'site_mode' => 'full' ) );
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( $tool, array( 'description' => 'Change a thing.', 'callback' => $callback ) );
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => $tool, 'arguments' => $args ),
			)
		);
		return ( new \AB_MCP_REST_Controller( $r ) )->handle( $request )->get_data()['result'];
	}

	public function testOnlyTheCoresOwnHandlersAreLeftToRestore(): void {
		// AlphaBridge MCP Pro 4.12 registers its own copies of these writers,
		// which put nothing back: the central guard refuses for them.
		$GLOBALS['wp_widget_factory'] = (object) array( 'widgets' => array( new \WP_Widget( 'text' ) ) );
		update_option( 'widget_text', array( 4 => array( 'title' => 'feed', 'feed' => array( 'accessToken' => 'STORED-AT', 'count' => 5 ) ), '_multiwidget' => 1 ) );
		$meta   = array( 'id' => 5, 'key' => 'settings', 'value' => array( 'api_key' => self::R, 'color' => 'blue' ) );
		$widget = array( 'id' => 'text-4', 'settings' => array( 'feed' => array( 'accessToken' => self::R, 'count' => 10 ) ) );
		try {
			RedactionProCopyProbe::$calls = 0;
			foreach ( array( 'wp_update_post_meta' => array( RedactionProCopyProbe::class, 'update_post_meta_tool' ), 'wp_update_widget' => array( RedactionProCopyProbe::class, 'update_widget' ) ) as $tool => $copy ) {
				$result = $this->call( $tool, $copy, 'wp_update_widget' === $tool ? $widget : $meta );
				self::assertTrue( $result['isError'], $tool );
				self::assertStringContainsString( 'Send the real value', $result['content'][0]['text'], $tool );
			}
			self::assertSame( 0, RedactionProCopyProbe::$calls, 'Neither copy ran.' );
			self::assertSame( array( 'api_key' => 'STORED-KEY', 'color' => 'red' ), get_post_meta( 5, 'settings', true ) );
			self::assertSame( 'STORED-AT', get_option( 'widget_text' )[4]['feed']['accessToken'] );

			// The core's own handlers put the stored value back.
			$result = $this->call( 'wp_update_post_meta', array( AB_MCP_Tools_Content::class, 'update_post_meta_tool' ), $meta );
			self::assertFalse( $result['isError'], (string) wp_json_encode( $result ) );
			self::assertSame( array( 'api_key' => 'STORED-KEY', 'color' => 'blue' ), get_post_meta( 5, 'settings', true ) );
			$result = $this->call( 'wp_update_widget', array( AB_MCP_Tools_Widgets::class, 'update_widget' ), $widget );
			self::assertFalse( $result['isError'], (string) wp_json_encode( $result ) );
			self::assertSame( array( 'accessToken' => 'STORED-AT', 'count' => 10 ), get_option( 'widget_text' )[4]['feed'] );
		} finally {
			unset( $GLOBALS['wp_widget_factory'] );
		}
	}

	public function testAPlaceholderInAListIsPutBackOnlyWhereTheListIsAsRead(): void {
		// Codex 9 #1: a list is matched by position, so only one as read is.
		$stored = array(
			'connections' => array( array( 'name' => 'production', 'api_key' => 'PROD-KEY' ), array( 'name' => 'staging', 'api_key' => 'STAGE-KEY' ) ),
			'color'       => 'red',
		);
		add_post_meta( 5, 'conns', $stored );
		$read = $this->answer( 'wp_get_post_meta', AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 5, 'key' => 'conns' ) ) )['value'];
		self::assertSame( array( array( 'name' => 'production', 'api_key' => self::R ), array( 'name' => 'staging', 'api_key' => self::R ) ), $read['connections'] );
		$write = static fn( $value ) => AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'conns', 'value' => $value ) );

		$before = $GLOBALS['ab_test_meta'];
		foreach (
			array(
				'removed'  => array( $read['connections'][1] ),
				'the last' => array( $read['connections'][0] ),
				'swapped'  => array( $read['connections'][1], $read['connections'][0] ),
				'inserted' => array( array( 'name' => 'dev', 'api_key' => 'DEV-KEY' ), $read['connections'][0], $read['connections'][1] ),
				'changed'  => array( $read['connections'][0], array( 'name' => 'stage', 'api_key' => self::R ) ),
			) as $why => $list
		) {
			$res = $write( array( 'connections' => $list, 'color' => 'blue' ) );
			self::assertInstanceOf( \WP_Error::class, $res, $why );
			self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code(), $why );
			self::assertStringContainsString( 'Send the real value', $res->get_error_message(), $why );
		}
		self::assertSame( $before, $GLOBALS['ab_test_meta'], 'Nothing was written.' );

		// As read, with another field changed: put back.
		$res = $write( array( 'connections' => $read['connections'], 'color' => 'blue' ) );
		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'connections' => $stored['connections'], 'color' => 'blue' ), get_post_meta( 5, 'conns', true ) );

		// Codex 10 #1: keys in another order are the same entry; a value changed is not.
		$reordered = array( array( 'api_key' => self::R, 'name' => 'production' ), array( 'api_key' => self::R, 'name' => 'staging' ) );
		$res       = $write( array( 'color' => 'green', 'connections' => $reordered ) );
		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( 'PROD-KEY', 'STAGE-KEY' ), array_column( get_post_meta( 5, 'conns', true )['connections'], 'api_key' ) );
		self::assertSame( 'green', get_post_meta( 5, 'conns', true )['color'] );
		$before = $GLOBALS['ab_test_meta'];
		$res    = $write( array( 'connections' => array( array( 'api_key' => self::R, 'name' => 'prod' ), $reordered[1] ) ) );
		self::assertSame( 'ab_mcp_redacted_value', $res->get_error_code() );
		self::assertSame( $before, $GLOBALS['ab_test_meta'] );

		// A changed entry without a placeholder changes; the one with it stays as stored.
		add_post_meta( 5, 'mixed', array( array( 'name' => 'production', 'api_key' => 'PROD-KEY' ), array( 'name' => 'staging', 'region' => 'eu' ) ) );
		$res = AB_MCP_Tools_Content::update_post_meta_tool( array( 'id' => 5, 'key' => 'mixed', 'value' => array( array( 'name' => 'production', 'api_key' => self::R ), array( 'name' => 'staging', 'region' => 'us' ) ) ) );
		self::assertIsArray( $res, is_object( $res ) ? $res->get_error_message() : '' );
		self::assertSame( array( array( 'name' => 'production', 'api_key' => 'PROD-KEY' ), array( 'name' => 'staging', 'region' => 'us' ) ), get_post_meta( 5, 'mixed', true ) );
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

/**
 * A stored object of a class that allows no copy, with a backslash in a
 * public property.
 */
final class RedactionNoCopyProbe {

	/** @var string Public, with a backslash. */
	public string $password = 'a\\b';

	private function __clone() {
	}
}

/**
 * A writer's own copy, as AlphaBridge MCP Pro 4.12 registers it: a subclass
 * of the core's tool class with the same method name, which writes what it
 * is sent and puts nothing back.
 */
final class RedactionProCopyProbe extends AB_MCP_Tools_Content {

	/** @var int How often a copy ran. */
	public static int $calls = 0;

	public static function update_post_meta_tool( $a ) {
		++self::$calls;
		update_post_meta( (int) $a['id'], (string) $a['key'], wp_slash( $a['value'] ) );
		return array( 'updated' => true );
	}

	public static function update_widget( $a ) {
		++self::$calls;
		return array( 'updated' => true );
	}
}
