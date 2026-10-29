<?php
/**
 * The seven tools that came with AlphaBridge MCP Pro only until 4.9.0 and are
 * free since: changing, adding and removing widgets, setting user and term
 * meta, optimizing the database tables and deleting the expired transients.
 *
 * They are Pro's code, so these checks are the ones that matter when code
 * changes hands: each tool is registered here, in Pro's group, with the same
 * capability, flags and hints, and it does what it did in Pro. Pro's own
 * test scripts (widget-map-test, meta-guard-pro-test) are carried over:
 *
 * - Widgets: every setting passes the widget's own update() and the
 *   widget_update_callback filter, as on the widgets screen; an unregistered
 *   type, a veto, a TypeError of the sanitiser and a value in a shape the
 *   widget does not keep are refused and nothing is written. Credentials
 *   wp_get_widgets leaves out stay as stored and stay out of the answer. The
 *   sidebar map is edited as stored, never as the filtered view. A write is
 *   read back before it counts.
 * - User meta: keys of roles, capabilities and sign-in state of any site of
 *   a network, keys starting with "_" and credential-like keys are refused;
 *   the right to the user and to the key is asked before anything is
 *   written. Term meta: the same for "_" and credentials, and the right to
 *   the term's meta.
 * - Optimizing touches only the tables of this site's prefix, by a name
 *   reduced to letters, digits and "_"; the clean-up deletes only expired
 *   transients, through WordPress.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Meta_Auth;
use AB_MCP_Tools_Ops;
use AB_MCP_Tools_Widgets;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/widgets-meta-ops-stubs.php';
require_once __DIR__ . '/../includes/tools/class-tools-base.php';
require_once __DIR__ . '/../includes/tools/class-tools-widgets.php';
require_once __DIR__ . '/../includes/tools/class-tools-meta-auth.php';
require_once __DIR__ . '/../includes/tools/class-tools-ops.php';

/** A widget whose sanitiser takes text only, as the core's do under PHP 8. */
final class StrictTextWidget extends \WP_Widget {
	public function update( $new_instance, $old_instance ) {
		return array( 'title' => trim( $new_instance['title'] ) );
	}
}

final class FreeSinceFourNineWidgetsMetaOpsTest extends TestCase {

	/**
	 * The tools, their group, capability and hints as Pro registered them.
	 * Hints in Pro's own notation (test/tool-descriptions-test.php):
	 * readOnly, destructive, idempotent, openWorld.
	 */
	private const MOVED = array(
		'wp_update_widget'      => array( 'widgets', 'edit_theme_options', '0101' ),
		'wp_add_widget'         => array( 'widgets', 'edit_theme_options', '0101' ),
		'wp_delete_widget'      => array( 'widgets', 'edit_theme_options', '0100' ),
		'wp_update_user_meta'   => array( 'meta-auth', 'edit_users', '0100' ),
		'wp_update_term_meta'   => array( 'meta-auth', 'manage_categories', '0101' ),
		'wp_db_optimize'        => array( 'ops', 'manage_options', '0100' ),
		'wp_cleanup_transients' => array( 'ops', 'manage_options', '0100' ),
	);

	/** The input schema of each, as Pro declares it: required keys and properties. */
	private const SCHEMA = array(
		'wp_update_widget'      => array( array( 'id', 'settings' ), array( 'id', 'settings' ) ),
		'wp_add_widget'         => array( array( 'id_base', 'sidebar' ), array( 'id_base', 'sidebar', 'settings', 'position' ) ),
		'wp_delete_widget'      => array( array( 'id' ), array( 'id' ) ),
		'wp_update_user_meta'   => array( array( 'user_id', 'key' ), array( 'user_id', 'key', 'value' ) ),
		'wp_update_term_meta'   => array( array( 'term_id', 'key' ), array( 'term_id', 'key', 'value' ) ),
		'wp_db_optimize'        => null,
		'wp_cleanup_transients' => null,
	);

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_way']              = array();
		$GLOBALS['ab_test_meta_writes']      = array();
		$GLOBALS['ab_test_widget_vetoes']    = false;
		$GLOBALS['ab_test_widget_violation'] = null;
		$GLOBALS['wp_widget_factory']        = (object) array( 'widgets' => array( new \WP_Widget( 'text' ) ) );
		$GLOBALS['wp_registered_sidebars']   = array( 'sidebar-1' => array( 'name' => 'Sidebar' ) );
		// Without array_version on purpose: the store has to add it, as
		// wp_set_sidebars_widgets() does.
		update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array(), 'sidebar-1' => array( 'text-2', 'text-9', 'token-2' ) ) );
		update_option( 'widget_text', array( 2 => array( 'title' => 'a' ), 9 => array( 'title' => 'hidden by a plugin' ), '_multiwidget' => 1 ) );
		update_option( 'widget_token', array( 2 => array( 'title' => 'protected base' ), '_multiwidget' => 1 ) );
		$GLOBALS['ab_test_writes'] = array();
		$GLOBALS['ab_test_can']    = static fn( string $cap, array $args ): bool => true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_widget_factory'], $GLOBALS['wp_registered_sidebars'], $GLOBALS['_wp_sidebars_widgets'], $GLOBALS['wpdb'] );
	}

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		foreach ( array( AB_MCP_Tools_Widgets::class => 'widgets', AB_MCP_Tools_Meta_Auth::class => 'meta-auth', AB_MCP_Tools_Ops::class => 'ops' ) as $class => $group ) {
			$r->set_current_group( $group, $group );
			$class::register( $r );
		}
		return $r;
	}

	private static function map(): array {
		return get_option( 'sidebars_widgets' );
	}

	private static function text( int $n ): ?array {
		return get_option( 'widget_text' )[ $n ] ?? null;
	}

	private static function code( $res ): string {
		self::assertInstanceOf( \WP_Error::class, $res );
		return (string) $res->get_error_code();
	}

	/* ------------------------------------------------------- registration */

	public function testTheSevenAreRegisteredAsProRegisteredThem(): void {
		$r = $this->registry();
		foreach ( self::MOVED as $name => [ $group, $cap, $hints ] ) {
			$def = $r->get( $name );
			self::assertIsArray( $def, $name );
			self::assertSame( $group, $def['group'], $name );
			self::assertSame( $cap, $def['capability'], $name );
			self::assertArrayNotHasKey( 'tier', $def, $name . ' is a tool of this plugin now.' );
			self::assertTrue( ! empty( $def['dangerous'] ), $name . ' keeps its flag: off until write access is on.' );
			self::assertFalse( AB_MCP_Site_Mode::runs_in_read( $name, $def ), $name . ' waits for write access.' );
			$a = AB_MCP_Tool_Registry::annotations( $name, $def, $name );
			self::assertSame(
				$hints,
				( $a['readOnlyHint'] ? '1' : '0' ) . ( $a['destructiveHint'] ? '1' : '0' ) . ( $a['idempotentHint'] ? '1' : '0' ) . ( $a['openWorldHint'] ? '1' : '0' ),
				$name . ': the hints of Pro.'
			);
			self::assertIsCallable( $def['callback'], $name );
			self::assertNotSame( '', $def['description'], $name );
			if ( null === self::SCHEMA[ $name ] ) {
				self::assertSame( array(), (array) $def['inputSchema']['properties'], $name . ' takes no arguments.' );
			} else {
				self::assertSame( self::SCHEMA[ $name ][0], $def['inputSchema']['required'], $name );
				self::assertSame( self::SCHEMA[ $name ][1], array_keys( $def['inputSchema']['properties'] ), $name );
			}
		}
		self::assertCount( 7, self::MOVED );
	}

	public function testAContentTokenMaySetTermMetaOnlyAsInPro(): void {
		// manage_categories is content work, so a content token runs
		// wp_update_term_meta, as it did with Pro; the others need full.
		$r = $this->registry();
		foreach ( array_keys( self::MOVED ) as $name ) {
			self::assertSame( 'wp_update_term_meta' === $name, AB_MCP_Tool_Registry::scope_allows( 'content', $name, $r->get( $name ) ), $name );
			self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'read', $name, $r->get( $name ) ), $name );
		}
	}

	public function testProRegisteringTheSameNameReplacesTheDefinition(): void {
		// Pro registers its copy of these names over the free ones, as it does
		// with wp_get_post: one definition per name, the last one counts.
		$r = $this->registry();
		$r->register( 'wp_update_widget', array( 'description' => 'Pro', 'capability' => 'edit_theme_options', 'tier' => 'pro', 'callback' => '__return_true' ) );
		self::assertSame( 'Pro', $r->get( 'wp_update_widget' )['description'] );
		self::assertSame( 1, count( array_filter( array_keys( $r->all() ), static fn( $n ) => 'wp_update_widget' === $n ) ) );
	}

	/* ------------------------------------------------------- add a widget */

	public function testANewWidgetGoesIntoTheStoredMapNotTheFilteredView(): void {
		$GLOBALS['_wp_sidebars_widgets'] = array( 'stale' => true ); // The copy wp_get_sidebars_widgets() keeps for the request.
		$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'new' ) ) );
		self::assertSame( array( 'added' => true, 'id' => 'text-10', 'sidebar' => 'sidebar-1' ), $r, 'The next free number.' );
		self::assertSame( array( 'text-2', 'text-9', 'token-2', 'text-10' ), self::map()['sidebar-1'], 'Appended; text-9, which a plugin may hide from the filtered view, keeps its place.' );
		self::assertSame( 3, self::map()['array_version'], 'Added on store, as wp_set_sidebars_widgets() would.' );
		self::assertArrayHasKey( '_wp_sidebars_widgets', $GLOBALS );
		self::assertNull( $GLOBALS['_wp_sidebars_widgets'], 'The request copy is cleared, so a core reader later in the request sees the new map.' );
		self::assertSame( array( 'title' => 'new', 'seen_number' => 10 ), self::text( 10 ), 'Stored under the new number; the widget was told that number before it sanitised.' );
		self::assertSame( 1, get_option( 'widget_text' )['_multiwidget'] );
		self::assertNull( $GLOBALS['ab_test_widget_violation'], 'Neither the filtered read nor wp_set_sidebars_widgets().' );
	}

	public function testANewWidgetPassesTheWidgetsOwnUpdateAndThenTheFilter(): void {
		$seen = array();
		add_filter(
			'widget_update_callback',
			static function ( $clean, $new, $old, $widget ) use ( &$seen ) {
				$seen = array( $clean, $new, $old, $widget->id_base );
				if ( is_array( $clean ) ) {
					$clean['by_filter'] = 1;
				}
				return $clean;
			}
		);
		$r      = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'x<script>alert(1)</script>y' ) ) );
		$stored = self::text( 10 );
		self::assertSame( 'text-10', $r['id'] );
		self::assertSame( 'xalert(1)y', $stored['title'], 'The widget\'s own sanitiser ran: what keeps script out for an account without unfiltered_html.' );
		self::assertSame( 1, $stored['by_filter'], 'And the widget_update_callback filter after it.' );
		self::assertSame( array( array( 'title' => 'xalert(1)y', 'seen_number' => 10 ), array( 'title' => 'x<script>alert(1)</script>y' ), array(), 'text' ), $seen, 'The filter gets the cleaned instance, the one sent, the old one and the widget, as WP_Widget::update_callback() hands them.' );
	}

	public function testAWidgetThatRefusesTheSettingsStopsTheSave(): void {
		$GLOBALS['ab_test_widget_vetoes'] = true;
		$before                           = get_option( 'widget_text' );
		$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'whatever' ) ) );
		self::assertSame( 'ab_mcp_widget_rejected', self::code( $r ) );
		self::assertSame( $before, get_option( 'widget_text' ), 'Nothing written, as WP_Widget::update_callback() skips the save.' );
		self::assertSame( 0, ab_test_writes( 'sidebars_widgets' ) );

		$r = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'b' ) ) );
		self::assertSame( 'ab_mcp_widget_rejected', self::code( $r ) );
		self::assertSame( $before, get_option( 'widget_text' ) );
	}

	public function testAnUnknownTypeOrSidebarIsRefused(): void {
		$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'calendar', 'sidebar' => 'sidebar-1' ) );
		self::assertSame( 'ab_mcp_unknown_base', self::code( $r ) );
		self::assertStringContainsString( 'Unknown widget type "calendar"', $r->get_error_message() );
		self::assertStringContainsString( ': text', $r->get_error_message(), 'The refusal lists the types there are.' );
		self::assertSame( 'ab_mcp_bad_base', self::code( AB_MCP_Tools_Widgets::add_widget( array( 'sidebar' => 'sidebar-1' ) ) ) );

		$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'footer-9' ) );
		self::assertSame( 'ab_mcp_no_sidebar', self::code( $r ) );
		self::assertStringContainsString( 'wp_list_sidebars', $r->get_error_message() );
		self::assertSame( 0, ab_test_writes( 'widget_text' ) + ab_test_writes( 'sidebars_widgets' ), 'Refused, nothing is written.' );

		$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'wp_inactive_widgets' ) );
		self::assertSame( 'text-10', $r['id'], 'The inactive widgets take one too.' );
		self::assertSame( array( 'text-10' ), self::map()['wp_inactive_widgets'] );
	}

	public function testATypeNoPluginRegistersIsNeverWritten(): void {
		// No widget type registered at all: the list of bases is empty and does
		// not refuse, the sanitiser does — fail closed.
		$GLOBALS['wp_widget_factory'] = (object) array( 'widgets' => array() );
		$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'token', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'x' ) ) );
		self::assertSame( 'ab_mcp_unregistered_widget', self::code( $r ) );
		$r = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'token-2', 'settings' => array( 'title' => 'x' ) ) );
		self::assertSame( 'ab_mcp_unregistered_widget', self::code( $r ), 'An option row of a type that is gone is not written either.' );
		self::assertSame( 'protected base', get_option( 'widget_token' )[2]['title'] );
		self::assertSame( 0, ab_test_writes( 'widget_token' ) + ab_test_writes( 'sidebars_widgets' ) );
	}

	public function testThePositionPlacesTheWidget(): void {
		AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'position' => 1 ) );
		self::assertSame( array( 'text-2', 'text-10', 'text-9', 'token-2' ), self::map()['sidebar-1'] );
		AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'position' => -4 ) );
		self::assertSame( 'text-11', self::map()['sidebar-1'][0], 'Negative counts as 0.' );
	}

	/* ---------------------------------------------------- update a widget */

	public function testAnUpdateKeepsTheCredentialsTheReaderLeftOut(): void {
		// Made-up placeholders, not credentials.
		$opt      = get_option( 'widget_text' );
		$opt[4]   = array( 'title' => 'feed', 'api_key' => 'PLACEHOLDER-K', 'feed' => array( 'url' => 'u', 'client_secret' => 'PLACEHOLDER-S', 'items' => array( 'a', 'b' ) ) );
		update_option( 'widget_text', $opt );
		$r      = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-4', 'settings' => array( 'title' => 'feed 2', 'feed' => array( 'url' => 'v', 'items' => array( 'a' ) ) ) ) );
		$stored = self::text( 4 );
		self::assertSame( 'feed 2', $stored['title'] );
		self::assertSame( array( 'url' => 'v', 'items' => array( 'a' ), 'client_secret' => 'PLACEHOLDER-S' ), $stored['feed'], 'What was sent is written; the nested credential left out stays as stored.' );
		self::assertSame( 'PLACEHOLDER-K', $stored['api_key'], 'And the one at the top.' );
		self::assertSame( 4, $stored['seen_number'] );

		self::assertTrue( $r['updated'] );
		self::assertSame( 'text-4', $r['id'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) json_encode( $r ), 'The answer leaves them out as wp_get_widgets does.' );
		self::assertSame( array( 'api_key', 'feed/client_secret' ), $r['hidden_keys'], 'And names them.' );
		self::assertSame( 'v', $r['settings']['feed']['url'] );

		AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-4', 'settings' => array( 'feed' => array( 'url' => 'v', 'client_secret' => 'PLACEHOLDER-NEW' ) ) ) );
		self::assertSame( 'PLACEHOLDER-NEW', self::text( 4 )['feed']['client_secret'], 'A credential that is sent is written.' );
	}

	public function testAnUpdateMergesTheTopLevelAndAnswersWithoutHiddenKeysWhenThereAreNone(): void {
		$r = AB_MCP_Tools_Widgets::update_widget( array( 'id' => ' text-2 ', 'settings' => array( 'text' => 'body' ) ) );
		self::assertSame( array( 'updated' => true, 'id' => 'text-2', 'settings' => array( 'title' => 'a', 'text' => 'body', 'seen_number' => 2 ) ), $r );
		self::assertSame( array( 'title' => 'hidden by a plugin' ), self::text( 9 ), 'The other instances stay.' );
	}

	public function testAnUnknownOrMalformedIdIsRefused(): void {
		self::assertSame( 'ab_mcp_no_widget', self::code( AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-7', 'settings' => array( 'title' => 'x' ) ) ) ) );
		self::assertSame( 'ab_mcp_no_widget', self::code( AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'search-2', 'settings' => array( 'title' => 'x' ) ) ) ) );
		foreach ( array( 'text', 'text-', '-2', 'text-2x', '' ) as $id ) {
			self::assertSame( 'ab_mcp_bad_widget', self::code( AB_MCP_Tools_Widgets::update_widget( array( 'id' => $id, 'settings' => array() ) ) ), $id );
			self::assertSame( 'ab_mcp_bad_widget', self::code( AB_MCP_Tools_Widgets::delete_widget( array( 'id' => $id ) ) ), $id );
		}
		self::assertSame( 0, ab_test_writes( 'widget_text' ) + ab_test_writes( 'sidebars_widgets' ) );
	}

	/* ------------------------------------------------------- the shape */

	public function testAListWhereTheWidgetKeepsTextIsRefused(): void {
		$before = get_option( 'widget_text' );
		$r      = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => array( 'a', 'b' ) ) ) );
		self::assertSame( 'ab_mcp_widget_settings_shape', self::code( $r ) );
		self::assertStringContainsString( 'settings.title: the widget keeps a text there', $r->get_error_message() );
		self::assertSame( $before, get_option( 'widget_text' ), 'Nothing written.' );

		$opt    = get_option( 'widget_text' );
		$opt[6] = array( 'title' => '', 'text' => 'x' );
		update_option( 'widget_text', $opt );
		$r = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-6', 'settings' => array( 'title' => array( 'a' ) ) ) );
		self::assertSame( 'ab_mcp_widget_settings_shape', self::code( $r ), 'An empty text is text too.' );
		$r = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-6', 'settings' => array( 'title' => 'now set' ) ) );
		self::assertSame( 'now set', self::text( 6 )['title'], 'Text there passes.' );
	}

	public function testListsAreNestedNoDeeperThanTheWidgetKeepsThem(): void {
		$before = get_option( 'widget_text' );
		$r      = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'n', 'cats' => array( 'x' => array( 1, 2 ) ) ) ) );
		self::assertSame( 'ab_mcp_widget_settings_shape', self::code( $r ) );
		self::assertStringContainsString( 'settings.cats is nested deeper', $r->get_error_message() );
		self::assertSame( $before, get_option( 'widget_text' ), 'A new widget: a list of lists is refused, nothing written.' );
		self::assertSame( 'ab_mcp_widget_settings_shape', self::code( AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'settings' => array( 'obj' => new \stdClass() ) ) ) ), 'An object is no list.' );

		$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'n', 'cats' => array( 1, 2 ), 'on' => true, 'none' => null ) ) );
		self::assertSame( array( 1, 2 ), self::text( (int) substr( $r['id'], 5 ) )['cats'], 'Text, a flat list, true and null pass.' );

		$opt    = get_option( 'widget_text' );
		$opt[6] = array( 'title' => 'rep', 'items' => array( array( 'label' => 'a' ) ) );
		update_option( 'widget_text', $opt );
		$r = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-6', 'settings' => array( 'items' => array( array( 'label' => 'b' ), array( 'label' => 'c' ) ) ) ) );
		self::assertSame( 'c', self::text( 6 )['items'][1]['label'], 'A field the widget keeps as a list of lists (a repeater) takes one in that form.' );
		$r = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-6', 'settings' => array( 'items' => array( array( 'label' => array( 'deep' ) ) ) ) ) );
		self::assertSame( 'ab_mcp_widget_settings_shape', self::code( $r ), '... but nothing deeper.' );
		self::assertSame( 'c', self::text( 6 )['items'][1]['label'] );
	}

	public function testASanitiserThatEndsWithATypeErrorRefusesAndNamesIt(): void {
		$GLOBALS['wp_widget_factory']->widgets[] = new StrictTextWidget( 'strict' );
		$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'strict', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => array( 'x' ) ) ) );
		self::assertSame( 'ab_mcp_widget_rejected', self::code( $r ) );
		self::assertStringContainsString( 'TypeError', $r->get_error_message() );
		self::assertStringContainsString( 'nothing was saved', $r->get_error_message() );
		self::assertFalse( get_option( 'widget_strict' ), 'Nothing written.' );
	}

	/* --------------------------------------------------- the read-back */

	/**
	 * One tool run against a stand-in whose update_option() loses the write
	 * of the widget row (widget-write-lost-runner.php): the bootstrap's
	 * update_option() always stores, so this needs a process of its own.
	 */
	private static function lostWrite( string $mode ): array {
		$out  = array();
		$exit = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/widget-write-lost-runner.php' ) . ' ' . escapeshellarg( $mode ) . ' 2>&1', $out, $exit );
		self::assertSame( 0, $exit, implode( "\n", $out ) );
		$res = json_decode( implode( "\n", $out ), true );
		self::assertIsArray( $res, implode( "\n", $out ) );
		return $res;
	}

	public function testAWriteThatDoesNotReadBackIsNoSuccess(): void {
		$r = self::lostWrite( 'update' );
		self::assertSame( 'ab_mcp_write_failed', $r['code'], 'update_option() cannot tell a lost write from an unchanged one; the read-back can.' );
		self::assertStringContainsString( 'Appearance › Widgets', $r['message'] );

		$r = self::lostWrite( 'add' );
		self::assertSame( 'ab_mcp_write_failed', $r['code'] );
		self::assertSame( array( 'widget_text' ), $r['writes'], 'The sidebar is not touched for a row that did not store.' );
		self::assertSame( array( 'text-2' ), $r['map']['sidebar-1'] );

		$r = self::lostWrite( 'delete' );
		self::assertSame( 'ab_mcp_write_failed', $r['code'], 'A row that is still there is no deletion.' );
		self::assertStringContainsString( 'still stored', $r['message'] );
	}

	/**
	 * Codex 29.10.2026: a sidebar map a filter turned down was reported as
	 * done. Now it is read back: adding takes the settings back, deleting
	 * leaves them, and both say so.
	 */
	public function testASidebarMapThatIsNotStoredIsNoSuccess(): void {
		add_filter( 'pre_update_option_sidebars_widgets', static fn( $value, $old ) => $old, 10, 2 );
		try {
			$before = get_option( 'widget_text' );
			$r      = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1' ) );
			self::assertSame( 'ab_mcp_write_failed', self::code( $r ) );
			self::assertStringContainsString( 'settings were taken back', $r->get_error_message() );
			self::assertSame( $before, get_option( 'widget_text' ), 'No settings without a place in a sidebar.' );

			$r = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-9' ) );
			self::assertSame( 'ab_mcp_write_failed', self::code( $r ) );
			self::assertArrayHasKey( 9, get_option( 'widget_text' ), 'The settings stay while the sidebar still names the widget.' );
		} finally {
			unset( $GLOBALS['ab_test_filters']['pre_update_option_sidebars_widgets'] );
		}
		self::assertSame( array( 'deleted' => true, 'id' => 'text-9' ), AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-9' ) ), 'Without the filter as before.' );

		// Codex 29.10.2026: a filter that stores the map but changes another
		// sidebar as well. What counts is where this widget is.
		add_filter(
			'pre_update_option_sidebars_widgets',
			static function ( $value ) {
				$value['wp_inactive_widgets'][] = 'other-1';
				return $value;
			}
		);
		try {
			$r = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1' ) );
			self::assertIsArray( $r, 'The widget is in its sidebar: added.' );
			self::assertArrayHasKey( (int) substr( $r['id'], 5 ), get_option( 'widget_text' ), 'Its settings stay.' );
			self::assertSame( array( 'deleted' => true, 'id' => $r['id'] ), AB_MCP_Tools_Widgets::delete_widget( array( 'id' => $r['id'] ) ), 'And it goes again: no sidebar names it.' );
		} finally {
			unset( $GLOBALS['ab_test_filters']['pre_update_option_sidebars_widgets'] );
		}
	}

	/* ---------------------------------------------------- delete a widget */

	public function testADeletedWidgetLeavesItsSidebarAndItsRow(): void {
		$r = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => "text-9\n" ) );
		self::assertSame( array( 'deleted' => true, 'id' => 'text-9' ), $r, 'A trailing newline is trimmed; the id comes back clean.' );
		self::assertSame( array( 'text-2', 'token-2' ), self::map()['sidebar-1'] );
		self::assertArrayNotHasKey( 9, get_option( 'widget_text' ) );
		self::assertSame( array( 'title' => 'a' ), self::text( 2 ) );
		self::assertSame( array( 'wp_inactive_widgets', 'sidebar-1', 'array_version' ), array_keys( self::map() ), 'The rest of the map is untouched.' );
		self::assertNull( $GLOBALS['ab_test_widget_violation'] );
	}

	public function testAnUnknownWidgetIsNotFoundAndTheMapIsNotWritten(): void {
		$r = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'nope-3' ) );
		self::assertSame( 'ab_mcp_no_widget', self::code( $r ) );
		self::assertSame( 0, ab_test_writes( 'sidebars_widgets' ), 'Nothing left the map, so it is not written.' );
	}

	public function testAWidgetOnlyInTheMapOrOnlyInTheRowIsRemovedToo(): void {
		update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'text-2', 'text-5' ), 'array_version' => 3 ) );
		self::assertSame( array( 'deleted' => true, 'id' => 'text-5' ), AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-5' ) ), 'An entry without settings.' );
		self::assertSame( array( 'text-2' ), self::map()['sidebar-1'] );
		self::assertSame( array( 'deleted' => true, 'id' => 'text-9' ), AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-9' ) ), 'Settings without an entry.' );
		self::assertArrayNotHasKey( 9, get_option( 'widget_text' ) );
	}

	public function testWithoutProsGuardOnlyARowTheWidgetsWroteIsTouched(): void {
		// A row of another plugin that is called widget_<something>: no
		// _multiwidget mark, so neither the sidebar entry nor the row goes.
		update_option( 'widget_token', array( 2 => array( 'key' => 'secret' ) ) );
		$r = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'token-2' ) );
		self::assertSame( 'ab_mcp_blocked', self::code( $r ) );
		self::assertSame( 'secret', get_option( 'widget_token' )[2]['key'] );
		self::assertContains( 'token-2', self::map()['sidebar-1'], 'Asked before the map is touched.' );
		self::assertSame( 0, ab_test_writes( 'sidebars_widgets' ) );
		update_option( 'widget_token', 'a text' );
		self::assertSame( 'ab_mcp_blocked', self::code( AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'token-2' ) ) ), 'Nor a row that is no list at all.' );

		// The row the widgets wrote goes as before, and so does an entry of
		// the map whose row is gone.
		update_option( 'widget_token', array( 2 => array( 'title' => 'w' ), '_multiwidget' => 1 ) );
		self::assertSame( array( 'deleted' => true, 'id' => 'token-2' ), AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'token-2' ) ) );
		update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'gone-4' ), 'array_version' => 3 ) );
		self::assertSame( array( 'deleted' => true, 'id' => 'gone-4' ), AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'gone-4' ) ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWithProsOptionGuardAProtectedBaseIsRefusedBeforeAnythingIsTouched(): void {
		require_once __DIR__ . '/pro-option-guard-stub.php';
		$GLOBALS['ab_test_option_guard_asked'] = array();
		$r = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'token-2' ) );
		self::assertSame( 'ab_mcp_blocked', self::code( $r ) );
		self::assertSame( array( 'widget_token' ), $GLOBALS['ab_test_option_guard_asked'], 'Asked about the row the base names.' );
		self::assertContains( 'token-2', self::map()['sidebar-1'], 'Before the map ...' );
		self::assertSame( 'protected base', get_option( 'widget_token' )[2]['title'], '... or the row is touched.' );
		self::assertSame( 0, ab_test_writes( 'sidebars_widgets' ) + ab_test_writes( 'widget_token' ) );

		$GLOBALS['wp_widget_factory']->widgets[] = new \WP_Widget( 'token' );
		self::assertSame( 'ab_mcp_blocked', self::code( AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'token-2', 'settings' => array( 'title' => 'x' ) ) ) ), 'Also for a type that is registered.' );
		self::assertSame( 'ab_mcp_blocked', self::code( AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'token', 'sidebar' => 'sidebar-1' ) ) ) );
		self::assertSame( 0, ab_test_writes( 'sidebars_widgets' ) + ab_test_writes( 'widget_token' ) );

		self::assertSame( 'text-10', AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1' ) )['id'], 'An ordinary base passes.' );
		self::assertContains( 'widget_text', $GLOBALS['ab_test_option_guard_asked'] );
	}

	/* -------------------------------------------------------- user meta */

	/** Pro's meta-guard-pro-test: keys that must be refused. */
	private const USER_META_REFUSED = array(
		'wp_capabilities',
		'wp_user_level',
		'session_tokens',
		'default_password_nonce',
		'_application_passwords',
		'_two_factor_totp_key',
		'_two_factor_backup_codes',
		'_two_factor_enabled_providers',
		'use_ssl',
		'api_key',
		'access_token',
		'client_secret',
		'token',
		'secret',
		'password',
		'my_token',
		'',
	);

	/** Pro's meta-guard-pro-test: ordinary fields that stay writable. */
	private const USER_META_WRITABLE = array(
		'first_name',
		'last_name',
		'nickname',
		'description',
		'locale',
		'twitter',
		'company_role',
		'token_count',
		'password_hint',
		'maxTokens',
		'billing_address_1',
		'wp_user-settings',
		'community-events-location',
	);

	private static function guarded( string $key ): bool {
		return ( new ReflectionMethod( AB_MCP_Tools_Meta_Auth::class, 'protected_user_meta' ) )->invoke( null, $key );
	}

	private static function prefixes( ?string $base, string $prefix ): void {
		$wpdb         = new \stdClass();
		$wpdb->prefix = $prefix;
		if ( null !== $base ) {
			$wpdb->base_prefix = $base;
		}
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function testTheUserMetaGuardRefusesRolesSignInAndCredentials(): void {
		self::prefixes( 'wp_', 'wp_' );
		foreach ( self::USER_META_REFUSED as $key ) {
			self::assertTrue( self::guarded( $key ), 'refused: ' . var_export( $key, true ) );
		}
		foreach ( self::USER_META_WRITABLE as $key ) {
			self::assertFalse( self::guarded( $key ), 'writable: ' . $key );
		}
	}

	public function testOnANetworkEverySitesRoleKeyIsRefused(): void {
		// Serving site 3: wp_capabilities belongs to the first site and
		// wp_2_capabilities to another one.
		self::prefixes( 'wp_', 'wp_3_' );
		foreach ( array( 'wp_capabilities', 'wp_2_capabilities', 'wp_3_capabilities', 'wp_2_user_level', 'wp_user_level' ) as $key ) {
			self::assertTrue( self::guarded( $key ), $key );
		}
		// A custom base prefix is honoured, the default one not hard-wired.
		self::prefixes( 'xy7_', 'xy7_4_' );
		foreach ( array( 'xy7_capabilities', 'xy7_4_capabilities', 'xy7_user_level' ) as $key ) {
			self::assertTrue( self::guarded( $key ), $key );
		}
		self::assertFalse( self::guarded( 'wp_capabilities' ), 'An ordinary key under base prefix xy7_.' );
		foreach ( array( 'xy7_capabilities_note', 'capabilities' ) as $key ) {
			self::assertFalse( self::guarded( $key ), $key );
		}
		// No base_prefix at all: the blog id is dropped from the prefix.
		self::prefixes( null, 'wp_3_' );
		foreach ( array( 'wp_capabilities', 'wp_2_capabilities', 'wp_3_capabilities' ) as $key ) {
			self::assertTrue( self::guarded( $key ), 'no base_prefix: ' . $key );
		}
		// An empty base_prefix does not make a bare "capabilities" match.
		self::prefixes( '', 'wp_' );
		self::assertFalse( self::guarded( 'capabilities' ) );
		self::assertTrue( self::guarded( 'wp_capabilities' ), 'It falls back to wp_.' );
	}

	public function testUserMetaIsWrittenWithTheRightToTheUserAndTheKey(): void {
		self::prefixes( 'wp_', 'wp_' );
		ab_test_add_user( 5 );
		$asked                  = array();
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$asked ): bool {
			$asked[] = array( $cap, $args );
			return true;
		};
		$r = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'Twitter Handle!', 'value' => 'C:\\x' ) );
		self::assertSame( array( 'updated' => true, 'user_id' => 5, 'key' => 'twitterhandle' ), $r, 'The key as sanitize_key() leaves it.' );
		self::assertSame( array( array( 'user', 5, 'twitterhandle', 'C:\\x' ) ), $GLOBALS['ab_test_meta_writes'] );
		self::assertSame( array( array( 'edit_user', array( 5 ) ), array( 'edit_user_meta', array( 5, 'twitterhandle' ) ) ), $asked, 'The right to this user, then to this key.' );

		AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'prefs' ) );
		AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'list', 'value' => array( 'a' => array( 1, true, null ) ) ) );
		self::assertSame( array( 'user', 5, 'prefs', '' ), $GLOBALS['ab_test_meta_writes'][1], 'Omitted: an empty string.' );
		self::assertSame( array( 'a' => array( 1, true, null ) ), $GLOBALS['ab_test_meta_writes'][2][3], 'Arrays may nest.' );
	}

	public function testUserMetaIsRefusedWithoutWritingAnything(): void {
		self::prefixes( 'wp_', 'wp_' );
		ab_test_add_user( 5 );

		$r = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 6, 'key' => 'nickname', 'value' => 'x' ) );
		self::assertSame( 'ab_mcp_not_found', self::code( $r ) );
		self::assertStringContainsString( 'wp_list_users', $r->get_error_message() );

		$r = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'wp_capabilities', 'value' => array( 'administrator' => true ) ) );
		self::assertSame( 'ab_mcp_protected_meta', self::code( $r ) );
		self::assertStringContainsString( 'wp_update_user', $r->get_error_message() );
		self::assertSame( 'ab_mcp_protected_meta', self::code( AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'Session_Tokens', 'value' => 'x' ) ) ), 'Sanitised first, then refused.' );

		$r = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'nickname', 'value' => new \stdClass() ) );
		self::assertSame( 'ab_mcp_bad_value', self::code( $r ) );
		self::assertSame( 'ab_mcp_bad_value', self::code( AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'nickname', 'value' => array( 'a' => array( new \stdClass() ) ) ) ) ), 'Also deep inside a list.' );

		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_user' !== $cap;
		$r                      = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'nickname', 'value' => 'x' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $r ) );
		self::assertStringStartsWith( 'Your account cannot edit this user.', $r->get_error_message() );
		self::assertStringContainsString( 'under Users', $r->get_error_message(), 'The refusal leads to the role, like every refusal for the role.' );

		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_user_meta' !== $cap;
		$r                      = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'nickname', 'value' => 'x' ) );
		self::assertSame( 'ab_mcp_protected_meta', self::code( $r ), 'A key an auth callback keeps.' );
		self::assertStringContainsString( 'auth callback', $r->get_error_message() );

		self::assertSame( array(), $GLOBALS['ab_test_meta_writes'], 'Refused, nothing is written.' );
	}

	/* -------------------------------------------------------- term meta */

	private static function term( int $id ): void {
		$GLOBALS['ab_test_way']['terms'][ $id ] = (object) array( 'term_id' => $id, 'taxonomy' => 'category', 'name' => 'News' );
	}

	public function testTermMetaIsWrittenWithTheRightToTheTermsMeta(): void {
		self::term( 8 );
		$asked                  = array();
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$asked ): bool {
			$asked[] = array( $cap, $args );
			return true;
		};
		$r = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'Color', 'value' => '#c00' ) );
		self::assertSame( array( 'updated' => true, 'term_id' => 8, 'key' => 'color' ), $r );
		self::assertSame( array( array( 'term', 8, 'color', '#c00' ) ), $GLOBALS['ab_test_meta_writes'] );
		self::assertSame( array( array( 'edit_term_meta', array( 8, 'color' ) ) ), $asked );
	}

	public function testTermMetaIsRefusedWithoutWritingAnything(): void {
		self::term( 8 );
		$r = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 9, 'key' => 'color', 'value' => 'x' ) );
		self::assertSame( 'ab_mcp_not_found', self::code( $r ) );
		self::assertStringContainsString( 'wp_list_terms', $r->get_error_message() );
		foreach ( array( '_thumbnail_id', 'api_key', 'client_secret', 'token' ) as $key ) {
			self::assertSame( 'ab_mcp_protected_meta', self::code( AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => $key, 'value' => 'x' ) ) ), $key );
		}
		self::assertSame( 'ab_mcp_bad_value', self::code( AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'color', 'value' => new \stdClass() ) ) ) );

		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_term_meta' !== $cap;
		$r                      = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'color', 'value' => 'x' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $r ) );
		self::assertStringStartsWith( 'Your account cannot edit meta for this term.', $r->get_error_message() );
		self::assertStringContainsString( 'under Users', $r->get_error_message() );

		self::assertSame( array(), $GLOBALS['ab_test_meta_writes'], 'Refused, nothing is written.' );
	}

	/* ---------------------------------------------------- the database */

	/** A $wpdb that answers get_col() from a list and notes every statement. */
	private static function wpdb( array $col ): object {
		return new class( $col ) {
			public $prefix      = 'wp_';
			public $base_prefix = 'wp_';
			public $options     = 'wp_options';
			public $queries = array();
			public $col;

			public function __construct( array $col ) {
				$this->col = $col;
			}

			public function esc_like( $text ) {
				return addcslashes( (string) $text, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				return array( $query, $args );
			}

			public function get_col( $prepared ) {
				$this->queries[] = $prepared;
				return $this->col;
			}

			public function query( $sql ) {
				$this->queries[] = $sql;
				return true;
			}

			public function tables( $scope = 'all', $prefix = true ) {
				return array( 'users' => 'wp_users', 'usermeta' => 'wp_usermeta', 'blogs' => 'wp_blogs', 'site' => 'wp_site', 'sitemeta' => 'wp_sitemeta' );
			}
		};
	}

	public function testOnlyTheTablesOfThisSiteAreOptimizedByACleanName(): void {
		$GLOBALS['wpdb'] = self::wpdb( array( 'wp_posts', 'wp_options', 'wp_x`; DROP TABLE wp_users; --', '`;--' ) );
		$r               = AB_MCP_Tools_Ops::db_optimize();
		self::assertSame( array( 'optimized' => 3, 'tables' => array( 'wp_posts', 'wp_options', 'wp_xDROPTABLEwp_users' ) ), $r );
		self::assertSame(
			array(
				array( 'SHOW TABLES LIKE %s', array( 'wp\_%' ) ),
				'OPTIMIZE TABLE `wp_posts`',
				'OPTIMIZE TABLE `wp_options`',
				'OPTIMIZE TABLE `wp_xDROPTABLEwp_users`',
			),
			$GLOBALS['wpdb']->queries,
			'The prefix as a LIKE pattern, its "_" escaped; a name only of letters, digits and "_"; one that is left empty is skipped.'
		);
	}

	/**
	 * Codex 29.10.2026: on a network's main site the prefix pattern also
	 * matched the other sites' tables and the shared ones. Without the
	 * network's right only this site's tables are optimized.
	 */
	public function testOnANetworksMainSiteOnlyItsOwnTablesAreOptimizedWithoutTheNetworkRight(): void {
		$GLOBALS['ab_test_multisite'] = true;
		try {
			$db              = self::wpdb( array( 'wp_posts', 'wp_options', 'wp_users', 'wp_usermeta', 'wp_2_posts', 'wp_15_options', 'wp_shop_orders' ) );
			$db->base_prefix = 'wp_';
			$GLOBALS['wpdb'] = $db;
			$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'manage_network_options' !== $cap;
			$r = AB_MCP_Tools_Ops::db_optimize();
			self::assertSame( array( 'wp_posts', 'wp_options', 'wp_shop_orders' ), $r['tables'], 'Not wp_users, wp_usermeta, wp_2_posts or wp_15_options.' );

			$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
			$db->queries = array();
			self::assertCount( 7, AB_MCP_Tools_Ops::db_optimize()['tables'], 'With the network right, all of them, as before.' );

			$db->prefix = 'wp_2_';
			$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'manage_network_options' !== $cap;
			$db->col = array( 'wp_2_posts', 'wp_2_options' );
			self::assertSame( array( 'wp_2_posts', 'wp_2_options' ), AB_MCP_Tools_Ops::db_optimize()['tables'], 'Another site: its prefix already narrows the list.' );
		} finally {
			$GLOBALS['ab_test_multisite'] = false;
		}
	}

	public function testOnlyExpiredTransientsAreDeletedThroughWordPress(): void {
		set_transient( 'old_a', 1 );
		set_transient( 'old_b', 2 );
		set_transient( 'fresh', 3 );
		$GLOBALS['wpdb'] = self::wpdb( array( '_transient_timeout_old_a', '_transient_timeout_old_b', '_transient_timeout_' ) );
		$before          = time();
		$r               = AB_MCP_Tools_Ops::cleanup_transients();
		self::assertSame( array( 'deleted' => 2 ), $r, 'A timeout row without a name is skipped.' );
		self::assertSame( array( 'fresh' => 3 ), $GLOBALS['ab_test_transients'], 'Deleted through WordPress, the fresh one left.' );
		[ $sql, $args ] = $GLOBALS['wpdb']->queries[0];
		self::assertSame( 'SELECT option_name FROM wp_options WHERE option_name LIKE %s AND option_value < %d', $sql );
		self::assertSame( '\_transient\_timeout\_%', $args[0], 'Rows starting with _transient_timeout_, so _site_transient_timeout_ is not among them.' );
		self::assertGreaterThanOrEqual( $before, $args[1] );
		self::assertLessThanOrEqual( time(), $args[1], 'Expired: the timeout is before now.' );
	}
}
