<?php
/**
 * The site mode: Read out of the box, Full only on purpose.
 *
 * What must hold:
 *
 * - A new installation and an updated one both start in Read. The read-only
 *   switch of earlier versions is gone after the update; administrators see
 *   a notice about the change once, until one of them dismisses it or
 *   switches the mode.
 * - In Read every tool the registry does not classify as reading is refused,
 *   wp_undo and unknown tools included, and the answer names the way: who
 *   switches, where, and that it is at the site owner's own risk. The
 *   writing tools stay listed, as they did under the read-only switch.
 * - Full lets every switched-on tool run, the powerful ones included; the
 *   fine-tuning switches single tools and groups off again.
 * - Only an administrator switches, with the form's own nonce; Full only
 *   with the box under the notice ticked and for the notice in force. Who,
 *   when, which notice and which plugin version is recorded in the option
 *   and in the log; back to Read needs no box and keeps the record.
 * - The switch is the first thing on the settings page.
 * - The server instructions say Read in one sentence, whatever a filter does.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use AbTestExit;
use AB_MCP_Admin;
use AB_MCP_Audit_Log;
use AB_MCP_Auth;
use AB_MCP_REST_Controller;
use AB_MCP_Security;
use AB_MCP_Settings;
use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use ReflectionProperty;
use WP_Error;

final class SiteModeTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 3 );
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * Every tool this plugin registers, each class in a group named after it
	 * (AB_MCP_Tools_Media → media), as the plugin groups them.
	 */
	private static function free_registry(): AB_MCP_Tool_Registry {
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/class-tools-*.php' ) as $file ) {
			require_once $file;
		}
		$r = new AB_MCP_Tool_Registry();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && ! ( new \ReflectionClass( $class ) )->isAbstract() && method_exists( $class, 'register' ) ) {
				$slug = strtolower( str_replace( '_', '-', substr( $class, strlen( 'AB_MCP_Tools_' ) ) ) );
				$r->set_current_group( $slug, $slug );
				$class::register( $r );
			}
		}
		return $r;
	}

	private static function allow_everything(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
	}

	private static function full(): void {
		update_option( 'ab_mcp_options', array( 'site_mode' => 'full' ) );
	}

	/** Options as a version before the site mode left them. */
	private static function before_the_update( array $options = array() ): void {
		update_option(
			'ab_mcp_options',
			$options + array(
				'rate_limit_per_min'         => 120,
				'audit_enabled'              => true,
				'read_only'                  => false,
				'connector_url_auth_enabled' => false,
			)
		);
		update_option( 'ab_mcp_tokens', array() );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	/** @param mixed ...$args */
	private function admin( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	/** The whole settings page, as an administrator sees it. */
	private function page(): DOMXPath {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$r                      = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts.' ) );
		$r->register( 'wp_update_post', array( 'description' => 'Update a post.' ) );
		ob_start();
		( new AB_MCP_Admin( $r ) )->render();
		return $this->xpath( (string) ob_get_clean() );
	}

	/** Post to an admin-post handler as user 3; returns how the request ended. */
	private function post( string $handler, array $post, ?\Closure $can = null ): AbTestExit {
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = $can ?? static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                           = $post;
		$_REQUEST                        = $post;
		try {
			( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ) )->$handler();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( $handler . ' ended without a redirect or a refusal.' );
	}

	private static function full_post(): array {
		return array(
			'_wpnonce'       => 'nonce-ab_mcp_site_mode',
			'action'         => 'ab_mcp_site_mode',
			'mode'           => 'full',
			'notice_version' => AB_MCP_Site_Mode::NOTICE_VERSION,
			'confirm_full'   => '1',
		);
	}

	private static function notice_of( AbTestExit $exit ): string {
		self::assertSame( 'redirect', $exit->kind, $exit->detail );
		parse_str( (string) parse_url( $exit->detail, PHP_URL_QUERY ), $q );
		return (string) ( $q['ab_notice'] ?? '' );
	}

	/** @return array<int,array> Log entries for the site mode, oldest first. */
	private static function mode_log(): array {
		return array_values( array_filter( (array) get_option( AB_MCP_Audit_Log::OPTION, array() ), static fn( $e ): bool => 'site_mode' === ( $e['tool'] ?? '' ) ) );
	}

	/* --------------------------------------------------- Read by default */

	public function testANewInstallationStartsInRead(): void {
		AB_MCP_Settings::install_defaults();

		$opts = get_option( 'ab_mcp_options' );
		self::assertSame( 'read', $opts['site_mode'] );
		self::assertArrayNotHasKey( 'read_only', $opts );
		self::assertArrayNotHasKey( AB_MCP_Settings::KEY_MODE_NOTICE, $opts, 'Nothing changed for a new site, so no notice.' );
		self::assertSame( 'read', AB_MCP_Site_Mode::get() );
	}

	public function testASiteThatNeverRanTheActivationStartsInRead(): void {
		// A core bundled by an add-on, or a site of a network, is never
		// activated itself; the plugin brings its options up on first load.
		AB_MCP_Settings::maybe_upgrade();

		self::assertSame( 'read', get_option( 'ab_mcp_options' )['site_mode'] );
		self::assertFalse( AB_MCP_Settings::get( AB_MCP_Settings::KEY_MODE_NOTICE, false ) );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
	}

	/** @return array<string,array{0:bool}> */
	public static function readOnlyBefore(): array {
		return array(
			'read-only was off' => array( false ),
			'read-only was on'  => array( true ),
		);
	}

	#[DataProvider( 'readOnlyBefore' )]
	public function testAnUpdatedSiteStartsInReadWhateverItAllowedBefore( bool $read_only ): void {
		self::before_the_update( array( 'read_only' => $read_only ) );

		AB_MCP_Settings::maybe_upgrade();

		$opts = get_option( 'ab_mcp_options' );
		self::assertSame( 'read', $opts['site_mode'] );
		self::assertArrayNotHasKey( 'read_only', $opts, 'The old switch is gone.' );
		self::assertTrue( $opts[ AB_MCP_Settings::KEY_MODE_NOTICE ], 'The administrators are told once.' );
		self::assertSame( 120, $opts['rate_limit_per_min'], 'Everything else stays.' );
	}

	public function testASiteWithConnectionsButNoOptionsCountsAsUpdated(): void {
		update_option( 'ab_mcp_tokens', array( array( 'hash' => 'h' ) ) );

		AB_MCP_Settings::maybe_upgrade();

		self::assertSame( 'read', AB_MCP_Site_Mode::get() );
		self::assertTrue( AB_MCP_Settings::get( AB_MCP_Settings::KEY_MODE_NOTICE, false ) );
	}

	public function testTheUpdateRunsOnceAndThenWritesNothing(): void {
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();
		$writes = ab_test_writes( 'ab_mcp_options' );

		AB_MCP_Settings::maybe_upgrade();
		AB_MCP_Settings::maybe_upgrade();

		self::assertSame( $writes, ab_test_writes( 'ab_mcp_options' ) );
	}

	public function testActivatingAgainKeepsTheModeAnAdministratorChose(): void {
		AB_MCP_Settings::install_defaults();
		AB_MCP_Site_Mode::switch_to_full( 3 );

		AB_MCP_Settings::install_defaults();
		AB_MCP_Settings::maybe_upgrade();

		self::assertTrue( AB_MCP_Site_Mode::is_full() );
		self::assertFalse( AB_MCP_Settings::get( AB_MCP_Settings::KEY_MODE_NOTICE, false ) );
	}

	public function testThePluginBringsItsOptionsUpToDateOnEveryLoad(): void {
		// An update in place runs no activation hook. The plugin's boot is not
		// run here (it needs WordPress); the end-to-end run on a real site
		// covers it. This holds the call and its place: before the registry,
		// so nothing reads the options before they know the mode.
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );
		$boot   = substr( $source, (int) strpos( $source, 'private function __construct()' ) );

		self::assertIsInt( strpos( $boot, 'AB_MCP_Settings::maybe_upgrade();' ) );
		self::assertLessThan( strpos( $boot, 'new AB_MCP_Tool_Registry()' ), strpos( $boot, 'AB_MCP_Settings::maybe_upgrade();' ) );
	}

	/** @return array<string,array{0:mixed}> */
	public static function notFull(): array {
		return array(
			'missing'  => array( null ),
			'upper'    => array( 'FULL' ),
			'true'     => array( true ),
			'one'      => array( 1 ),
			'expert'   => array( 'expert' ),
			'array'    => array( array( 'full' ) ),
		);
	}

	#[DataProvider( 'notFull' )]
	public function testAnythingButExactlyFullReadsAsRead( $stored ): void {
		update_option( 'ab_mcp_options', null === $stored ? array() : array( 'site_mode' => $stored ) );

		self::assertSame( 'read', AB_MCP_Site_Mode::get() );
		self::assertFalse( AB_MCP_Site_Mode::allows( 'wp_update_post', array() ) );
	}

	public function testEachSiteOfANetworkHasItsOwnMode(): void {
		$GLOBALS['ab_test_multisite'] = true;
		$GLOBALS['ab_test_sites']     = array( 1, 2 );
		AB_MCP_Site_Mode::switch_to_full( 3 );

		switch_to_blog( 2 );
		self::assertSame( 'read', AB_MCP_Site_Mode::get(), 'Site 2 did not switch.' );
		restore_current_blog();
		self::assertSame( 'full', AB_MCP_Site_Mode::get() );
	}

	/* -------------------------------------------------------- the gate */

	public function testInReadEveryWritingToolIsRefusedWithTheWay(): void {
		self::allow_everything();
		$writers = 0;
		$readers = 0;
		foreach ( self::free_registry()->all() as $name => $def ) {
			$res = AB_MCP_Security::authorize( $name, $def );
			if ( AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
				self::assertTrue( $res, $name . ' reads, so it runs in Read.' );
				++$readers;
				continue;
			}
			++$writers;
			self::assertInstanceOf( WP_Error::class, $res, $name . ' writes, so Read refuses it.' );
			self::assertSame( 'ab_mcp_read_mode', $res->get_error_code(), $name );
			self::assertStringContainsString( 'the tool "' . $name . '" writes', $res->get_error_message() );
		}
		self::assertGreaterThan( 10, $writers );
		self::assertGreaterThan( 10, $readers );
	}

	public function testTheRefusalNamesWhoSwitchesWhereAndTheRisk(): void {
		self::assertSame(
			'AlphaBridge MCP is in read mode on this site; the tool "wp_update_post" writes. An administrator can switch to Full at the top of Settings → AlphaBridge MCP — switching it on can be destructive and is at the site owner\'s own risk.',
			AB_MCP_Site_Mode::refusal( 'wp_update_post' )->get_error_message()
		);
	}

	/** @return array<string,array{0:string,1:array}> */
	public static function writersFromElsewhere(): array {
		return array(
			'undo (Pro)'         => array( 'wp_undo', array( 'capability' => 'manage_options' ) ),
			'undo discard (Pro)' => array( 'wp_undo_discard', array( 'capability' => 'manage_options' ) ),
			'unknown name'       => array( 'wp_frobnicate', array() ),
			'flagged writing'    => array( 'wp_list_things', array( 'readonly' => false ) ),
		);
	}

	#[DataProvider( 'writersFromElsewhere' )]
	public function testUndoAndToolsNobodyClassifiedAreRefusedInRead( string $name, array $def ): void {
		self::allow_everything();

		$res = AB_MCP_Security::authorize( $name, $def );

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'ab_mcp_read_mode', $res->get_error_code() );
	}

	public function testAToolFlaggedAsReadingRunsInRead(): void {
		self::allow_everything();

		// The Pro add-on flags its undo list so.
		self::assertTrue( AB_MCP_Security::authorize( 'wp_undo_list', array( 'readonly' => true ) ) );
	}

	public function testReadOverridesTheWidestAccessLevel(): void {
		self::allow_everything();
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );

		self::assertSame( 'ab_mcp_read_mode', AB_MCP_Security::authorize( 'wp_delete_post', array( 'dangerous' => true ) )->get_error_code() );
	}

	public function testFullLetsEveryToolRunTheMightyOnesIncluded(): void {
		self::full();
		self::allow_everything();
		$mighty = 0;
		foreach ( self::free_registry()->all() as $name => $def ) {
			self::assertTrue( AB_MCP_Security::authorize( $name, $def ), $name . ' runs in Full.' );
			$mighty += empty( $def['dangerous'] ) ? 0 : 1;
		}
		self::assertGreaterThan( 2, $mighty, 'The powerful tools were among them.' );
	}

	public function testFineTuningSwitchesSingleToolsOffAgainInFull(): void {
		self::full();
		self::allow_everything();
		update_option( 'ab_mcp_tool_state', array( 'wp_delete_post' => false ) );

		$res = AB_MCP_Security::authorize( 'wp_delete_post', array( 'dangerous' => true ) );
		self::assertSame( 'ab_mcp_tool_disabled', $res->get_error_code() );
		self::assertStringContainsString( 'Settings → AlphaBridge MCP → Fine-tuning', $res->get_error_message() );
		self::assertTrue( AB_MCP_Security::authorize( 'wp_update_post', array() ) );
	}

	public function testFineTuningSwitchesAWholeGroupOff(): void {
		self::full();
		$r = self::free_registry();
		$groups = $r->groups();
		self::assertArrayHasKey( 'media', $groups );
		$keep = array_values( array_diff( array_keys( $r->all() ), $groups['media']['tools'] ) );

		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                  = array(
			'_wpnonce'      => 'nonce-ab_mcp_save',
			'enabled_tools' => $keep,
		);
		$_REQUEST               = $_POST;
		try {
			( new AB_MCP_Admin( $r ) )->handle_save();
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
		}

		self::allow_everything();
		foreach ( $groups['media']['tools'] as $name ) {
			self::assertSame( 'ab_mcp_tool_disabled', AB_MCP_Security::authorize( $name, $r->get( $name ) )->get_error_code(), $name );
		}
		self::assertTrue( AB_MCP_Security::authorize( 'wp_update_post', $r->get( 'wp_update_post' ) ) );
	}

	public function testInReadTheWritingToolsStayListedAsUnderTheReadOnlySwitch(): void {
		$r     = self::free_registry();
		$list  = ( new ReflectionMethod( AB_MCP_REST_Controller::class, 'tools_list' ) )->invoke( new AB_MCP_REST_Controller( $r ) );
		$names = array_column( $list['tools'], 'name' );

		self::assertContains( 'wp_update_post', $names );
		self::assertContains( 'wp_delete_post', $names, 'Every tool is on until switched off, the Mighty ones too.' );
		self::assertCount( count( $r->all() ), $names );
	}

	public function testAToolRefusedInReadIsLoggedAsDenied(): void {
		self::allow_everything();
		$r = self::free_registry();
		$m = new ReflectionMethod( AB_MCP_REST_Controller::class, 'tools_call' );
		$m->invoke(
			new AB_MCP_REST_Controller( $r ),
			1,
			array(
				'name'      => 'wp_create_post',
				'arguments' => array( 'title' => 'X' ),
			)
		);

		$last = AB_MCP_Audit_Log::recent( 1 )[0];
		self::assertSame( 'wp_create_post', $last['tool'] );
		self::assertSame( 'denied', $last['status'] );
		self::assertStringContainsString( 'read mode', $last['message'] );
		self::assertSame( array(), $GLOBALS['ab_test_inserted'], 'Nothing was written.' );
	}

	/* --------------------------------- switches saved before the update */

	public function testSwitchesSavedBeforeTheUpdateDoNotHoldTheMightyToolsOff(): void {
		// Saving the old form wrote «off» for every Mighty tool, which was
		// their default then; it records no decision.
		self::before_the_update();
		update_option(
			'ab_mcp_tool_state',
			array(
				'wp_delete_post' => false,
				'wp_create_term' => false,
				'wp_list_posts'  => true,
			)
		);
		AB_MCP_Settings::maybe_upgrade();

		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_delete_post', array( 'dangerous' => true ) ) );
		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_create_term', array() ), 'A tool that was on by default and switched off stays off.' );
		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_list_posts', array() ) );
	}

	public function testOnceTheSwitchesAreSavedAgainOffMeansOff(): void {
		self::before_the_update();
		update_option( 'ab_mcp_tool_state', array( 'wp_delete_post' => false ) );
		AB_MCP_Settings::maybe_upgrade();

		AB_MCP_Settings::set_tool_state( array( 'wp_delete_post' => false ) );

		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_delete_post', array( 'dangerous' => true ) ) );
		self::assertFalse( AB_MCP_Settings::get( AB_MCP_Settings::KEY_LEGACY_SWITCHES, false ) );
	}

	public function testOnANewSiteOffMeansOffForMightyToolsToo(): void {
		AB_MCP_Settings::install_defaults();
		update_option( 'ab_mcp_tool_state', array( 'wp_delete_post' => false ) );

		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_delete_post', array( 'dangerous' => true ) ) );
		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_delete_media', array( 'dangerous' => true ) ), 'Not switched off: on.' );
	}

	/* ------------------------------------------------- the instructions */

	public function testTheInstructionsSayReadInOneSentenceWhateverAFilterDoes(): void {
		$controller = new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() );
		$m          = new ReflectionMethod( AB_MCP_REST_Controller::class, 'instructions' );
		$sentence   = AB_MCP_Site_Mode::instructions_sentence();

		self::assertSame( 1, preg_match_all( '/[.!?](\s|$)/', $sentence ), 'One sentence.' );
		self::assertStringContainsString( 'switches it to Full at the top of Settings → AlphaBridge MCP', $sentence );
		self::assertStringEndsWith( ' ' . $sentence, $m->invoke( $controller ) );

		add_filter( 'ab_mcp_instructions', static fn(): string => 'Add-on text only.' );
		self::assertSame( 'Add-on text only. ' . $sentence, $m->invoke( $controller ), 'A filter cannot drop it.' );

		self::full();
		self::assertSame( 'Add-on text only.', $m->invoke( $controller ) );
	}

	/* ------------------------------------------- switching on the page */

	public function testSwitchingToFullRecordsWhoWhenWhichNoticeAndWhichVersion(): void {
		$changed = array();
		add_action(
			'ab_mcp_site_mode_changed',
			static function ( $mode, $user ) use ( &$changed ) {
				$changed[] = array( $mode, $user );
			},
			10,
			2
		);
		$before = time();

		$exit = $this->post( 'handle_site_mode', self::full_post() );

		self::assertSame( 'mode_full', self::notice_of( $exit ) );
		self::assertTrue( AB_MCP_Site_Mode::is_full() );
		$record = AB_MCP_Site_Mode::confirmation();
		self::assertSame( array( 'user_id', 'user_login', 'time', 'notice_version', 'plugin_version', 'locale' ), array_keys( $record ) );
		self::assertSame( 3, $record['user_id'] );
		self::assertSame( 'user3', $record['user_login'] );
		self::assertGreaterThanOrEqual( $before, $record['time'] );
		self::assertLessThanOrEqual( time(), $record['time'] );
		self::assertSame( AB_MCP_Site_Mode::NOTICE_VERSION, $record['notice_version'] );
		self::assertSame( AB_MCP_VERSION, $record['plugin_version'] );
		self::assertSame( 'en_US', $record['locale'] );
		self::assertSame( $record, get_option( 'ab_mcp_options' )[ AB_MCP_Site_Mode::KEY_CONFIRMATION ], 'Kept in the option.' );

		$log = self::mode_log();
		self::assertCount( 1, $log );
		self::assertSame( 'ok', $log[0]['status'] );
		self::assertSame( 3, $log[0]['user'] );
		self::assertStringContainsString( 'Full switched on by user3 (user 3)', $log[0]['message'] );
		self::assertStringContainsString( 'notice version ' . AB_MCP_Site_Mode::NOTICE_VERSION . ' confirmed', $log[0]['message'] );
		self::assertStringContainsString( 'plugin ' . AB_MCP_VERSION, $log[0]['message'] );
		self::assertSame( array( array( 'full', 3 ) ), $changed );
	}

	/** @return array<string,array{0:array}> */
	public static function unticked(): array {
		$base = self::full_post();
		$none = $base;
		unset( $none['confirm_full'] );
		return array(
			'box not sent' => array( $none ),
			'box empty'    => array( array( 'confirm_full' => '' ) + $base ),
			'box zero'     => array( array( 'confirm_full' => '0' ) + $base ),
			'box "on"'     => array( array( 'confirm_full' => 'on' ) + $base ),
		);
	}

	#[DataProvider( 'unticked' )]
	public function testFullNeedsTheTickedBox( array $post ): void {
		$exit = $this->post( 'handle_site_mode', $post );

		self::assertSame( 'mode_unconfirmed', self::notice_of( $exit ) );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
		self::assertNull( AB_MCP_Site_Mode::confirmation() );
		self::assertSame( array(), self::mode_log() );
	}

	/** @return array<string,array{0:mixed}> */
	public static function staleVersions(): array {
		return array(
			'missing' => array( null ),
			'older'   => array( '0' ),
			'other'   => array( 'x' ),
		);
	}

	#[DataProvider( 'staleVersions' )]
	public function testAFormFromAnotherNoticeIsRefused( $version ): void {
		$post = self::full_post();
		if ( null === $version ) {
			unset( $post['notice_version'] );
		} else {
			$post['notice_version'] = $version;
		}

		self::assertSame( 'mode_stale', self::notice_of( $this->post( 'handle_site_mode', $post ) ) );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
		self::assertNull( AB_MCP_Site_Mode::confirmation() );
	}

	/** @return array<string,array{0:array}> */
	public static function unsigned(): array {
		$base  = self::full_post();
		$none  = $base;
		unset( $none['_wpnonce'] );
		return array(
			'no nonce'              => array( $none ),
			'nonce of another form' => array( array( '_wpnonce' => 'nonce-ab_mcp_save' ) + $base ),
		);
	}

	#[DataProvider( 'unsigned' )]
	public function testFullNeedsTheFormsOwnNonce( array $post ): void {
		$exit = $this->post( 'handle_site_mode', $post );

		self::assertSame( 'die', $exit->kind );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
	}

	public function testOnlyAnAdministratorSwitchesToFull(): void {
		$exit = $this->post( 'handle_site_mode', self::full_post(), static fn(): bool => false );

		self::assertSame( 'die', $exit->kind );
		self::assertSame( 'Insufficient permissions.', $exit->detail );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
	}

	public function testAnUnknownModeChangesNothing(): void {
		AB_MCP_Site_Mode::switch_to_full( 3 );

		self::assertSame( 'mode_unknown', self::notice_of( $this->post( 'handle_site_mode', array( 'mode' => 'expert' ) + self::full_post() ) ) );
		self::assertTrue( AB_MCP_Site_Mode::is_full() );
	}

	public function testBackToReadNeedsNoBoxAndKeepsTheRecord(): void {
		AB_MCP_Site_Mode::switch_to_full( 3 );
		$record = AB_MCP_Site_Mode::confirmation();

		$exit = $this->post(
			'handle_site_mode',
			array(
				'_wpnonce' => 'nonce-ab_mcp_site_mode',
				'mode'     => 'read',
			)
		);

		self::assertSame( 'mode_read', self::notice_of( $exit ) );
		self::assertSame( 'read', AB_MCP_Site_Mode::get() );
		self::assertSame( $record, AB_MCP_Site_Mode::confirmation(), 'Who accepted the notice last stays documented.' );
		$log = self::mode_log();
		self::assertStringContainsString( 'Read switched on by user3 (user 3)', end( $log )['message'] );
	}

	public function testBackToReadNeedsTheNonceAndAnAdministratorToo(): void {
		AB_MCP_Site_Mode::switch_to_full( 3 );

		self::assertSame( 'die', $this->post( 'handle_site_mode', array( 'mode' => 'read' ) )->kind );
		self::assertSame( 'die', $this->post( 'handle_site_mode', array( '_wpnonce' => 'nonce-ab_mcp_site_mode', 'mode' => 'read' ), static fn(): bool => false )->kind );
		self::assertTrue( AB_MCP_Site_Mode::is_full() );
	}

	/* ---------------------------------------------------- the page itself */

	public function testTheSwitchIsTheFirstThingOnThePage(): void {
		$x     = $this->page();
		$first = $x->query( '//div[contains(@class, "ab-mcp")]/*[1]' )->item( 0 );

		self::assertSame( 'section', $first->nodeName );
		self::assertSame( 'ab-mode', $first->getAttribute( 'id' ) );
		self::assertSame( 1, $x->query( '//section[@id="ab-mode"]/following::hr[contains(@class, "wp-header-end")]' )->length, 'Notices land below it.' );
		self::assertSame( 1, $x->query( '//section[@id="ab-mode"]/following::*[contains(concat(" ", @class, " "), " ab-hero ")]' )->length );
		self::assertSame( 0, $x->query( '//section[@id="ab-mode"]/preceding::*[contains(concat(" ", @class, " "), " notice ")]' )->length );
	}

	public function testInReadTheSwitchToFullAsksForTheBox(): void {
		$x    = $this->xpath( $this->admin( 'mode_card_html' ) );
		$card = $x->query( '//section[@id="ab-mode"]' )->item( 0 );
		$form = $x->query( './/form', $card )->item( 0 );

		self::assertStringContainsString( 'ab-mode--read', $card->getAttribute( 'class' ) );
		self::assertSame( 'Read', trim( $x->query( './/*[contains(@class, "ab-mode__word")]', $card )->item( 0 )->textContent ), 'The state in one word.' );
		self::assertSame( 'https://example.test/wp-admin/admin-post.php', $form->getAttribute( 'action' ) );
		self::assertSame( 'post', $form->getAttribute( 'method' ) );
		$field = static fn( string $name ) => $x->query( './/input[@name="' . $name . '"]', $form )->item( 0 );
		self::assertSame( 'nonce-ab_mcp_site_mode', $field( '_wpnonce' )->getAttribute( 'value' ) );
		self::assertSame( 'ab_mcp_site_mode', $field( 'action' )->getAttribute( 'value' ) );
		self::assertSame( 'full', $field( 'mode' )->getAttribute( 'value' ) );
		self::assertSame( AB_MCP_Site_Mode::NOTICE_VERSION, $field( 'notice_version' )->getAttribute( 'value' ) );

		$box = $field( 'confirm_full' );
		self::assertSame( 'checkbox', $box->getAttribute( 'type' ) );
		self::assertSame( '1', $box->getAttribute( 'value' ) );
		self::assertFalse( $box->hasAttribute( 'checked' ), 'Not ticked in advance.' );
		self::assertTrue( $box->hasAttribute( 'required' ) );
		self::assertSame( AB_MCP_Site_Mode::checkbox_text(), trim( $box->parentNode->textContent ) );
		self::assertSame( AB_MCP_Site_Mode::notice_text(), trim( $x->query( './/*[contains(@class, "ab-mode__warning")]', $card )->item( 0 )->textContent ) );
		self::assertSame( 'Switch to Full', trim( $x->query( './/button[@type="submit"]', $form )->item( 0 )->textContent ) );
	}

	public function testTheNoticeAndTheBoxSayWhatWasDecided(): void {
		self::assertSame( 'Full lets AI assistants create, change and delete content, files, settings and code on this live site. Switching it on can be destructive and is at your own risk. Make sure you have a current backup.', AB_MCP_Site_Mode::notice_text() );
		self::assertSame( 'I understand that switching to Full can be destructive and is at my own risk, and I have a current backup.', AB_MCP_Site_Mode::checkbox_text() );
	}

	public function testInFullTheCardWarnsSaysSinceWhenAndSwitchesBackWithoutABox(): void {
		$record = AB_MCP_Site_Mode::switch_to_full( 3 );
		$x      = $this->xpath( $this->admin( 'mode_card_html' ) );
		$card   = $x->query( '//section[@id="ab-mode"]' )->item( 0 );

		self::assertStringContainsString( 'ab-mode--full', $card->getAttribute( 'class' ), 'The warning colour.' );
		self::assertSame( 'Full', trim( $x->query( './/*[contains(@class, "ab-mode__word")]', $card )->item( 0 )->textContent ) );
		self::assertSame( 'Full since ' . wp_date( 'Y-m-d H:i', $record['time'] ) . ', confirmed by user3', trim( $x->query( './/*[contains(@class, "ab-mode__since")]', $card )->item( 0 )->textContent ) );
		self::assertSame( 0, $x->query( './/input[@name="confirm_full"]', $card )->length );
		self::assertSame( 'read', $x->query( './/input[@name="mode"]', $card )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 'Switch back to Read', trim( $x->query( './/button[@type="submit"]', $card )->item( 0 )->textContent ) );
		self::assertSame( AB_MCP_Site_Mode::notice_text(), trim( $x->query( './/*[contains(@class, "ab-mode__warning")]', $card )->item( 0 )->textContent ) );
	}

	public function testFullSetByCodeSaysThatNothingWasConfirmed(): void {
		self::full();

		self::assertSame( 'Full is on; no confirmation on the settings page is recorded for it.', AB_MCP_Site_Mode::full_since_text() );
	}

	/* --------------------------------------------- the notice after updating */

	public function testAfterTheUpdateAdministratorsSeeTheNoticeOnTheirScreens(): void {
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;

		$x = $this->xpath( $this->admin( 'mode_notice' ) );

		$notice = $x->query( '//div[contains(@class, "ab-mode-notice")]' )->item( 0 );
		self::assertNotNull( $notice );
		self::assertStringContainsString( 'AlphaBridge MCP now only reads on this site.', $notice->textContent );
		self::assertStringContainsString( 'switches to Full at the top of Settings → AlphaBridge MCP', $notice->textContent );
		self::assertStringContainsString( 'can be destructive and is at your own risk', $notice->textContent );
		self::assertSame( 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp', $x->query( './/a', $notice )->item( 0 )->getAttribute( 'href' ) );
		self::assertSame( 'nonce-ab_mcp_mode_notice', $x->query( './/input[@name="_wpnonce"]', $notice )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 'ab_mcp_mode_notice', $x->query( './/input[@name="action"]', $notice )->item( 0 )->getAttribute( 'value' ) );
	}

	public function testTheNoticeIsForAdministratorsAfterAnUpdateOnly(): void {
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		self::assertSame( '', $this->admin( 'mode_notice' ), 'Not for other roles.' );

		ab_test_reset();
		AB_MCP_Settings::install_defaults();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		self::assertSame( '', $this->admin( 'mode_notice' ), 'Not on a new site.' );
	}

	public function testOnThePluginsPageTheNoticeStandsUnderTheSwitch(): void {
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_GET                   = array( 'page' => 'alphabridge-mcp' );

		self::assertSame( '', $this->admin( 'mode_notice' ), 'Not twice.' );
		$x = $this->page();
		self::assertSame( 1, $x->query( '//section[@id="ab-mode"]/following-sibling::*[1][contains(@class, "ab-mode-notice")]' )->length );
	}

	public function testDismissingTheNoticeNeedsTheNonceAndAnAdministrator(): void {
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();

		self::assertSame( 'die', $this->post( 'handle_mode_notice_dismiss', array() )->kind );
		self::assertSame( 'die', $this->post( 'handle_mode_notice_dismiss', array( '_wpnonce' => 'nonce-ab_mcp_mode_notice' ), static fn(): bool => false )->kind );
		self::assertTrue( AB_MCP_Settings::get( AB_MCP_Settings::KEY_MODE_NOTICE, false ) );

		$exit = $this->post( 'handle_mode_notice_dismiss', array( '_wpnonce' => 'nonce-ab_mcp_mode_notice' ) );
		self::assertSame( 'mode_notice_dismissed', self::notice_of( $exit ) );
		self::assertFalse( AB_MCP_Settings::get( AB_MCP_Settings::KEY_MODE_NOTICE, false ) );
		self::assertSame( 'read', AB_MCP_Site_Mode::get(), 'Dismissing changes nothing else.' );
	}

	public function testSwitchingTheModeEndsTheNotice(): void {
		foreach ( array( 'full' => self::full_post(), 'read' => array( '_wpnonce' => 'nonce-ab_mcp_site_mode', 'mode' => 'read' ) ) as $mode => $post ) {
			ab_test_reset();
			self::before_the_update();
			AB_MCP_Settings::maybe_upgrade();

			$this->post( 'handle_site_mode', $post );

			self::assertSame( $mode, AB_MCP_Site_Mode::get() );
			self::assertFalse( AB_MCP_Settings::get( AB_MCP_Settings::KEY_MODE_NOTICE, false ), $mode );
		}
	}

	public function testTheNoticesAfterSwitching(): void {
		foreach ( array( 'mode_full' => 'notice-success', 'mode_read' => 'notice-success', 'mode_unconfirmed' => 'notice-error', 'mode_stale' => 'notice-error', 'mode_unknown' => 'notice-error', 'mode_notice_dismissed' => 'notice-success' ) as $key => $class ) {
			$_GET = array( 'ab_notice' => $key );
			self::assertStringContainsString( $class, $this->admin( 'notice' ), $key );
		}
	}
}
