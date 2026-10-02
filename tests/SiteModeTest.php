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
 *   wp_undo and unknown tools included, and so is every reading tool marked
 *   Mighty (the readers of code, files, the database, logs or credentials),
 *   whatever its switch and the token's scope say. Each answer says why and
 *   names the way: who switches, where, and that it is at the site owner's
 *   own risk. The refused tools stay listed, as the writing ones did under
 *   the read-only switch.
 * - Full lets every switched-on tool run, the powerful ones included, the
 *   Mighty readers too; the fine-tuning switches single tools and groups off
 *   again.
 * - Only an administrator switches, with the form's own nonce; Full only
 *   with the box under the notice ticked and for the notice in force, in
 *   the wording the form showed. Who, when, which notice in which wording,
 *   which plugin version and how (settings page or code) is recorded in the
 *   option, in the history of the mode and in the log; back to Read needs no
 *   box and keeps the record. «Clear log» leaves the history alone.
 * - After an update, a powerful tool that only reads keeps in Full, where
 *   alone it can run, what it had before: a saved off, and without a saved
 *   switch the old default, off — unless it is marked Mighty only since the
 *   site mode ('mighty_since'), which was on by default before. Powerful
 *   writing tools are on in Full whatever was saved.
 * - The switch is the first thing on the settings page; in Read its card
 *   says what assistants can read, an add-on adds what its tools read
 *   there, and the Mighty readers of code, files, the database, logs or
 *   credentials wait for Full; the band counts only what can run.
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
		$r->register( 'wp_get_user_meta', array( 'description' => 'Read profile fields.', 'dangerous' => true ) );
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
			'notice_hash'    => AB_MCP_Site_Mode::notice_hash(),
			'confirm_full'   => '1',
		);
	}

	/** Switch to Full the way the settings page does, as user 3. */
	private function confirm_full(): array {
		self::assertSame( 'mode_full', self::notice_of( $this->post( 'handle_site_mode', self::full_post() ) ) );
		return AB_MCP_Site_Mode::confirmation();
	}

	/** Tools of a Pro site that are marked Mighty and only read, as Pro defines them. */
	private static function mighty_readers(): array {
		return array(
			'wp_db_query'         => array( 'dangerous' => true, 'capability' => 'manage_options' ),
			'wp_deploy_read'      => array( 'dangerous' => true, 'capability' => 'manage_options' ),
			// Reads by its flag, not by its name: the refusal must look at
			// the definition to say why.
			'wp_deploy_diff'      => array( 'dangerous' => true, 'readonly' => true, 'capability' => 'manage_options' ),
			'wp_read_plugin_file' => array( 'dangerous' => true, 'capability' => 'edit_plugins' ),
			'wp_read_upload_file' => array( 'dangerous' => true, 'capability' => 'manage_options' ),
			'wp_get_error_log'    => array( 'dangerous' => true, 'capability' => 'manage_options' ),
			'wp_get_user_meta'    => array( 'dangerous' => true, 'capability' => 'edit_users' ),
		);
	}

	private static function scope( string $scope ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, $scope );
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

	public function testInReadOnlyTheOrdinaryReadersRunAndEveryOtherToolIsRefusedWithTheWay(): void {
		self::allow_everything();
		$writers = 0;
		$readers = 0;
		$mighty  = array();
		foreach ( self::free_registry()->all() as $name => $def ) {
			$res = AB_MCP_Security::authorize( $name, $def );
			if ( AB_MCP_Tool_Registry::is_read_only( $name, $def ) && empty( $def['dangerous'] ) ) {
				self::assertTrue( $res, $name . ' reads and is not Mighty, so it runs in Read.' );
				++$readers;
				continue;
			}
			self::assertInstanceOf( WP_Error::class, $res, $name . ' writes or is Mighty, so Read refuses it.' );
			self::assertSame( 'ab_mcp_read_mode', $res->get_error_code(), $name );
			if ( AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
				$mighty[] = $name;
				self::assertStringContainsString( 'the tool "' . $name . '" is marked Mighty, and read mode runs no tool marked Mighty, also none that only reads', $res->get_error_message() );
				continue;
			}
			++$writers;
			self::assertStringContainsString( 'the tool "' . $name . '" writes', $res->get_error_message() );
		}
		self::assertGreaterThan( 10, $writers );
		self::assertGreaterThan( 10, $readers );
		self::assertSame( array( 'wp_get_user_meta' ), $mighty, 'The Mighty reader of this plugin.' );
	}

	public function testTheRefusalNamesWhoSwitchesWhereAndTheRisk(): void {
		self::assertSame(
			'AlphaBridge MCP is in read mode on this site; the tool "wp_update_post" writes. An administrator can switch to Full at the top of Settings → AlphaBridge MCP — switching it on can be destructive and is at the site owner\'s own risk.',
			AB_MCP_Site_Mode::refusal( 'wp_update_post' )->get_error_message()
		);
		self::assertSame(
			'AlphaBridge MCP is in read mode on this site; the tool "wp_db_query" is marked Mighty, and read mode runs no tool marked Mighty, also none that only reads. An administrator can switch to Full at the top of Settings → AlphaBridge MCP — switching it on can be destructive and is at the site owner\'s own risk.',
			AB_MCP_Site_Mode::refusal( 'wp_db_query', self::mighty_readers()['wp_db_query'] )->get_error_message()
		);
		self::assertSame( AB_MCP_Site_Mode::refusal( 'wp_frobnicate' )->get_error_message(), AB_MCP_Site_Mode::refusal( 'wp_frobnicate', array( 'dangerous' => true ) )->get_error_message(), 'A Mighty tool that writes is refused because it writes.' );
	}

	/** @return array<string,array{0:string}> */
	public static function scopes(): array {
		return array(
			'scope read'    => array( 'read' ),
			'scope content' => array( 'content' ),
			'scope full'    => array( 'full' ),
		);
	}

	#[DataProvider( 'scopes' )]
	public function testInReadTheMightyReadersAreRefusedWithTheWayWhateverTheScope( string $scope ): void {
		AB_MCP_Settings::install_defaults();
		self::allow_everything();
		self::scope( $scope );

		foreach ( self::mighty_readers() as $name => $def ) {
			self::assertTrue( AB_MCP_Tool_Registry::is_read_only( $name, $def ), $name . ' only reads.' );
			self::assertTrue( AB_MCP_Settings::is_tool_enabled( $name, $def ), $name . ' is switched on: Read refuses it, not its switch.' );
			self::assertFalse( AB_MCP_Site_Mode::allows( $name, $def ), $name );
			$res = AB_MCP_Security::authorize( $name, $def );
			self::assertInstanceOf( WP_Error::class, $res, $name . ' does not run in Read with scope ' . $scope );
			self::assertSame( 'ab_mcp_read_mode', $res->get_error_code(), $name );
			self::assertStringContainsString( '"' . $name . '" is marked Mighty', $res->get_error_message() );
			self::assertStringContainsString( 'switch to Full at the top of Settings → AlphaBridge MCP', $res->get_error_message(), 'The answer names the way.' );
			self::assertStringContainsString( 'own risk', $res->get_error_message() );
		}
		self::assertTrue( AB_MCP_Security::authorize( 'wp_get_post', array() ), 'An ordinary reader runs.' );
	}

	public function testInFullTheMightyReadersRun(): void {
		AB_MCP_Settings::install_defaults();
		AB_MCP_Site_Mode::switch_to_full( 3 );
		self::allow_everything();

		foreach ( self::mighty_readers() as $name => $def ) {
			self::assertTrue( AB_MCP_Site_Mode::allows( $name, $def ), $name );
			self::assertTrue( AB_MCP_Security::authorize( $name, $def ), $name . ' runs in Full.' );
		}
		self::scope( 'read' );
		self::assertTrue( AB_MCP_Security::authorize( 'wp_db_query', self::mighty_readers()['wp_db_query'] ), 'A read token runs it in Full, as before the mode.' );
	}

	public function testWhatRunsInReadIsAReaderThatIsNotMighty(): void {
		self::assertTrue( AB_MCP_Site_Mode::runs_in_read( 'wp_list_posts', array() ) );
		self::assertTrue( AB_MCP_Site_Mode::runs_in_read( 'wp_undo_list', array( 'readonly' => true ) ), 'Flagged as reading.' );
		self::assertFalse( AB_MCP_Site_Mode::runs_in_read( 'wp_get_user_meta', array( 'dangerous' => true ) ), 'Mighty.' );
		self::assertFalse( AB_MCP_Site_Mode::runs_in_read( 'wp_list_things', array( 'dangerous' => 1, 'readonly' => true ) ), 'Mighty, whatever the truthy value.' );
		self::assertFalse( AB_MCP_Site_Mode::runs_in_read( 'wp_update_post', array() ), 'Writes.' );
		self::assertFalse( AB_MCP_Site_Mode::runs_in_read( 'wp_frobnicate', array() ), 'Unknown.' );
		self::assertTrue( AB_MCP_Tool_Registry::is_mighty( array( 'dangerous' => true ) ) );
		self::assertFalse( AB_MCP_Tool_Registry::is_mighty( array( 'dangerous' => false ) ) );
		self::assertFalse( AB_MCP_Tool_Registry::is_mighty( array() ) );
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

	public function testInReadTheRefusedToolsStayListedAsUnderTheReadOnlySwitch(): void {
		$r     = self::free_registry();
		$list  = ( new ReflectionMethod( AB_MCP_REST_Controller::class, 'tools_list' ) )->invoke( new AB_MCP_REST_Controller( $r ) );
		$names = array_column( $list['tools'], 'name' );

		self::assertContains( 'wp_update_post', $names );
		self::assertContains( 'wp_delete_post', $names, 'Every tool is on until switched off, the Mighty ones too.' );
		self::assertContains( 'wp_get_user_meta', $names, 'The Mighty reader too.' );
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

	/** @return array<string,array{0:bool}> */
	public static function readOnlyBeforeAndStored(): array {
		return array(
			'read-only was on, switches saved'   => array( true ),
			'read-only was off, switches saved'  => array( false ),
		);
	}

	#[DataProvider( 'readOnlyBeforeAndStored' )]
	public function testAfterTheUpdateMightyReadersWaitForFullAndKeepTheirOffThere( bool $read_only ): void {
		// The database query, the file readers, the profile fields: in Read
		// the mode refuses them, whatever was saved; in Full, where alone they
		// run, an off saved before the update still holds them off until an
		// administrator switches them on under Fine-tuning.
		self::before_the_update( array( 'read_only' => $read_only ) );
		$old = array( 'wp_list_posts' => true, 'wp_delete_post' => false );
		foreach ( self::mighty_readers() as $name => $def ) {
			$old[ $name ] = false;
		}
		update_option( 'ab_mcp_tool_state', $old );
		AB_MCP_Settings::maybe_upgrade();
		self::allow_everything();

		foreach ( array( 'read', 'full' ) as $scope ) {
			self::scope( $scope );
			foreach ( self::mighty_readers() as $name => $def ) {
				self::assertTrue( AB_MCP_Tool_Registry::is_read_only( $name, $def ), $name . ' only reads.' );
				self::assertFalse( AB_MCP_Settings::is_tool_enabled( $name, $def ), $name );
				$res = AB_MCP_Security::authorize( $name, $def );
				self::assertInstanceOf( WP_Error::class, $res, $name . ' does not run in Read with scope ' . $scope );
				self::assertSame( 'ab_mcp_read_mode', $res->get_error_code(), $name . ': Read answers first.' );
			}
		}

		AB_MCP_Site_Mode::switch_to_full( 3 );
		self::scope( 'full' );
		foreach ( self::mighty_readers() as $name => $def ) {
			$res = AB_MCP_Security::authorize( $name, $def );
			self::assertInstanceOf( WP_Error::class, $res, $name . ' keeps its off in Full.' );
			self::assertSame( 'ab_mcp_tool_disabled', $res->get_error_code(), $name );
			self::assertStringContainsString( 'Fine-tuning', $res->get_error_message(), 'The answer names the way.' );
		}
		self::assertTrue( AB_MCP_Security::authorize( 'wp_delete_post', array( 'dangerous' => true ) ), 'A writing Mighty tool follows Full.' );
	}

	public function testAfterTheUpdateAMightyReaderWithoutASavedSwitchStaysOffInFullToo(): void {
		// No saved switch: a tool the form never saw (an add-on installed
		// after the last save). Its old default was off, and the settings page
		// showed it so; Full opens writing, not what the site kept closed.
		self::before_the_update();
		update_option( 'ab_mcp_tool_state', array( 'wp_list_posts' => true ) );
		AB_MCP_Settings::maybe_upgrade();
		self::allow_everything();
		$read = self::mighty_readers()['wp_deploy_read'];

		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_deploy_read', $read ) );
		self::assertSame( 'ab_mcp_read_mode', AB_MCP_Security::authorize( 'wp_deploy_read', $read )->get_error_code(), 'Read answers first.' );
		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_db_execute', array( 'dangerous' => true ) ), 'One that writes waits for Full only.' );
		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_get_post', array() ), 'An ordinary one is on.' );

		AB_MCP_Site_Mode::switch_to_full( 3 );
		$res = AB_MCP_Security::authorize( 'wp_deploy_read', $read );
		self::assertInstanceOf( WP_Error::class, $res, 'Off in Full too.' );
		self::assertSame( 'ab_mcp_tool_disabled', $res->get_error_code() );
		self::assertStringContainsString( 'Fine-tuning', $res->get_error_message(), 'The answer names the way.' );
		self::assertTrue( AB_MCP_Security::authorize( 'wp_db_execute', array( 'dangerous' => true ) ) );
	}

	public function testOnASiteThatNeverSavedItsSwitchesTheMightyReadersStayOffInFull(): void {
		// The old version wrote no switches until the form was saved; every
		// Mighty tool was off by default there, every other one on.
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();
		self::assertSame( array(), get_option( 'ab_mcp_tool_state' ), 'Nothing saved.' );
		self::assertTrue( AB_MCP_Settings::get( AB_MCP_Settings::KEY_LEGACY_SWITCHES, false ) );
		AB_MCP_Site_Mode::switch_to_full( 3 );
		self::allow_everything();

		foreach ( self::mighty_readers() as $name => $def ) {
			$res = AB_MCP_Security::authorize( $name, $def );
			self::assertInstanceOf( WP_Error::class, $res, $name . ' was off by default before and stays off in Full.' );
			self::assertSame( 'ab_mcp_tool_disabled', $res->get_error_code(), $name );
		}
		$on = 0;
		foreach ( self::free_registry()->all() as $name => $def ) {
			if ( AB_MCP_Tool_Registry::is_read_only( $name, $def ) && ! empty( $def['dangerous'] ) ) {
				continue;
			}
			self::assertTrue( AB_MCP_Security::authorize( $name, $def ), $name . ' runs in Full, the writing Mighty ones included.' );
			++$on;
		}
		self::assertGreaterThan( 30, $on );
	}

	public function testAReaderMarkedMightyOnlySinceTheModesFollowsItsOldDefault(): void {
		// Ordinary before, so on by default then (an add-on marks such a
		// reader with the version that made it Mighty). Without a saved switch
		// it runs in Full; an off saved while it was ordinary still holds.
		$since = array( 'dangerous' => true, 'mighty_since' => '4.6.0', 'capability' => 'manage_options' );
		self::before_the_update();
		update_option( 'ab_mcp_tool_state', array( 'wp_list_posts' => true, 'wp_db_schema' => false ) );
		AB_MCP_Settings::maybe_upgrade();
		self::allow_everything();

		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_db_tables', $since ) );
		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_db_tables', array( 'dangerous' => true ) ), 'Without the mark it was Mighty before, so off.' );
		self::assertSame( 'ab_mcp_read_mode', AB_MCP_Security::authorize( 'wp_db_tables', $since )->get_error_code(), 'Read refuses it all the same.' );

		AB_MCP_Site_Mode::switch_to_full( 3 );
		self::assertTrue( AB_MCP_Security::authorize( 'wp_db_tables', $since ) );
		self::assertSame( 'ab_mcp_tool_disabled', AB_MCP_Security::authorize( 'wp_db_schema', $since )->get_error_code(), 'Switched off before: off.' );
	}

	public function testAfterTheUpdateAMightyReaderThatWasOnRunsInFull(): void {
		self::before_the_update();
		update_option( 'ab_mcp_tool_state', array( 'wp_db_query' => true ) );
		AB_MCP_Settings::maybe_upgrade();
		self::allow_everything();

		self::assertSame( 'ab_mcp_read_mode', AB_MCP_Security::authorize( 'wp_db_query', self::mighty_readers()['wp_db_query'] )->get_error_code(), 'Not in Read.' );
		AB_MCP_Site_Mode::switch_to_full( 3 );
		self::assertTrue( AB_MCP_Security::authorize( 'wp_db_query', self::mighty_readers()['wp_db_query'] ) );
	}

	public function testSavingTheSwitchesAfterTheUpdateKeepsWhatThePageShowed(): void {
		self::before_the_update();
		update_option( 'ab_mcp_tool_state', array( 'wp_get_user_meta' => false, 'wp_delete_post' => false ) );
		AB_MCP_Settings::maybe_upgrade();
		$r = self::free_registry();
		self::assertTrue( $r->get( 'wp_get_user_meta' )['dangerous'] ?? false, 'wp_get_user_meta is a Mighty tool of this plugin.' );
		self::assertTrue( AB_MCP_Tool_Registry::is_read_only( 'wp_get_user_meta', $r->get( 'wp_get_user_meta' ) ) );

		// The page shows each switch as is_tool_enabled() says; sent unchanged.
		$keep = array();
		foreach ( $r->all() as $name => $def ) {
			if ( AB_MCP_Settings::is_tool_enabled( $name, $def ) ) {
				$keep[] = $name;
			}
		}
		self::assertNotContains( 'wp_get_user_meta', $keep );
		self::assertContains( 'wp_delete_post', $keep );
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                  = array( '_wpnonce' => 'nonce-ab_mcp_save', 'enabled_tools' => $keep );
		$_REQUEST               = $_POST;
		try {
			( new AB_MCP_Admin( $r ) )->handle_save();
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
		}

		self::assertFalse( AB_MCP_Settings::get( AB_MCP_Settings::KEY_LEGACY_SWITCHES, false ) );
		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_get_user_meta', $r->get( 'wp_get_user_meta' ) ), 'Still off: nothing was widened by saving.' );
		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_delete_post', $r->get( 'wp_delete_post' ) ) );
	}

	public function testOnANewSiteTheMightyReadersAreOnAndRunOnlyInFull(): void {
		// Every switch is on out of the box; Read refuses the Mighty readers
		// all the same, Full runs them.
		AB_MCP_Settings::install_defaults();
		self::allow_everything();

		foreach ( self::mighty_readers() as $name => $def ) {
			self::assertTrue( AB_MCP_Settings::is_tool_enabled( $name, $def ), $name );
			self::assertSame( 'ab_mcp_read_mode', AB_MCP_Security::authorize( $name, $def )->get_error_code(), $name );
		}
		AB_MCP_Site_Mode::switch_to_full( 3 );
		foreach ( self::mighty_readers() as $name => $def ) {
			self::assertTrue( AB_MCP_Security::authorize( $name, $def ), $name );
		}
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
		self::assertStringContainsString( 'every tool that creates, changes or deletes, and every tool marked Mighty that reads code, files, the database, logs or credentials, is refused', $sentence, 'Says that the Mighty readers wait for Full too.' );
		self::assertStringNotContainsString( 'no code, files, database, logs or credentials', $sentence, 'Not more than the mode keeps: ordinary readers may meet a credential a guard does not recognise.' );
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
		self::assertSame( array( 'user_id', 'user_login', 'time', 'notice_version', 'plugin_version', 'locale', 'source', 'notice_hash', 'notice_text', 'checkbox_text' ), array_keys( $record ) );
		self::assertSame( 3, $record['user_id'] );
		self::assertSame( 'user3', $record['user_login'] );
		self::assertGreaterThanOrEqual( $before, $record['time'] );
		self::assertLessThanOrEqual( time(), $record['time'] );
		self::assertSame( AB_MCP_Site_Mode::NOTICE_VERSION, $record['notice_version'] );
		self::assertSame( AB_MCP_VERSION, $record['plugin_version'] );
		self::assertSame( 'en_US', $record['locale'] );
		self::assertSame( 'settings_form', $record['source'], 'Confirmed with the box on the settings page.' );
		self::assertSame( AB_MCP_Site_Mode::notice_text(), $record['notice_text'], 'The wording on the screen.' );
		self::assertSame( AB_MCP_Site_Mode::checkbox_text(), $record['checkbox_text'] );
		self::assertSame( hash( 'sha256', $record['notice_text'] . "\n" . $record['checkbox_text'] ), $record['notice_hash'] );
		self::assertSame( $record, get_option( 'ab_mcp_options' )[ AB_MCP_Site_Mode::KEY_CONFIRMATION ], 'Kept in the option.' );

		$log = self::mode_log();
		self::assertCount( 1, $log );
		self::assertSame( 'ok', $log[0]['status'] );
		self::assertSame( 3, $log[0]['user'] );
		self::assertStringContainsString( 'Full switched on by user3 (user 3) on the settings page', $log[0]['message'] );
		self::assertStringContainsString( 'notice version ' . AB_MCP_Site_Mode::NOTICE_VERSION . ' confirmed', $log[0]['message'] );
		self::assertStringContainsString( 'wording sha256 ' . $record['notice_hash'], $log[0]['message'] );
		self::assertStringContainsString( 'plugin ' . AB_MCP_VERSION, $log[0]['message'] );
		self::assertLessThanOrEqual( 300, mb_strlen( $log[0]['message'] ), 'The log clips at 300 characters; nothing of it is cut.' );
		self::assertStringEndsWith( 'locale en_US.', $log[0]['message'] );
		self::assertSame( array( array( 'full', 3 ) ), $changed );
		self::assertSame( array( array( 'mode' => 'full' ) + $record ), AB_MCP_Site_Mode::history(), 'And in the history.' );
	}

	public function testTheVersionOfTheNoticeIsTiedToItsEnglishWording(): void {
		// The hash of the English notice and box per version of the notice.
		// Changing either text without raising NOTICE_VERSION fails here: add
		// the new version with the hash of its wording, never edit an old one.
		$hashes = array(
			'1' => 'e63a129b792c19f2ffa0625d200070fa821bd84ba172150a7c24264399a3b310',
		);

		self::assertArrayHasKey( AB_MCP_Site_Mode::NOTICE_VERSION, $hashes );
		self::assertSame( $hashes[ AB_MCP_Site_Mode::NOTICE_VERSION ], AB_MCP_Site_Mode::notice_hash() );
	}

	public function testFullSetByCodeIsRecordedAsSuchAndNotAsConfirmed(): void {
		$record = AB_MCP_Site_Mode::switch_to_full( 3 );

		self::assertSame( 'code', $record['source'] );
		self::assertArrayNotHasKey( 'notice_text', $record, 'Nobody saw a notice.' );
		self::assertArrayNotHasKey( 'notice_hash', $record );
		self::assertSame( 'Full since ' . wp_date( 'Y-m-d H:i', $record['time'] ) . ', set by code, not confirmed on this page', AB_MCP_Site_Mode::full_since_text() );
		$log = self::mode_log();
		self::assertStringContainsString( 'by code, without the box on the settings page; notice version 1 not confirmed', end( $log )['message'] );
		self::assertStringNotContainsString( ' confirmed,', end( $log )['message'] );

		// Anything but the form's own word is code.
		AB_MCP_Site_Mode::switch_to_full( 3, 'admin' );
		self::assertSame( 'code', AB_MCP_Site_Mode::confirmation()['source'] );
	}

	public function testTheHistoryKeepsEverySwitchAndClearingTheLogLeavesIt(): void {
		$first = $this->confirm_full();
		$this->post( 'handle_site_mode', array( '_wpnonce' => 'nonce-ab_mcp_site_mode', 'mode' => 'read' ) );
		AB_MCP_Site_Mode::switch_to_full( 3 );

		$this->post( 'handle_audit_clear', array( '_wpnonce' => 'nonce-ab_mcp_audit_clear' ) );

		self::assertSame( array(), self::mode_log(), 'The log is empty.' );
		$history = AB_MCP_Site_Mode::history();
		self::assertSame( array( 'full', 'read', 'full' ), array_column( $history, 'mode' ) );
		self::assertSame( array( 'settings_form', 'settings_form', 'code' ), array_column( $history, 'source' ) );
		self::assertSame( $first['notice_text'], $history[0]['notice_text'], 'The earlier confirmation, with its wording, although the record now holds the later switch.' );
		self::assertSame( 'user3', $history[1]['user_login'] );
		self::assertSame( 'code', AB_MCP_Site_Mode::confirmation()['source'] );
	}

	public function testTheHistoryKeepsTheLatestSwitchesOnly(): void {
		for ( $i = 0; $i < AB_MCP_Site_Mode::HISTORY_MAX + 5; $i++ ) {
			AB_MCP_Site_Mode::switch_to_read( 3 );
		}
		AB_MCP_Site_Mode::switch_to_full( 3 );

		$history = AB_MCP_Site_Mode::history();
		self::assertCount( AB_MCP_Site_Mode::HISTORY_MAX, $history );
		self::assertSame( 'full', end( $history )['mode'], 'The newest is last.' );
		self::assertSame( 20, AB_MCP_Site_Mode::HISTORY_MAX );
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

	/** @return array<string,array{0:mixed}> */
	public static function staleWordings(): array {
		return array(
			'missing'          => array( null ),
			'empty'            => array( '' ),
			'another wording'  => array( hash( 'sha256', 'Full lets assistants write.' . "\n" . 'OK' ) ),
			'upper case'       => array( 'UPPER' ),
		);
	}

	#[DataProvider( 'staleWordings' )]
	public function testAFormThatShowedAnotherWordingIsRefused( $hash ): void {
		// A language pack updated between loading and sending, or the page
		// loaded in another language: the box was ticked under another text.
		$post = self::full_post();
		if ( null === $hash ) {
			unset( $post['notice_hash'] );
		} else {
			$post['notice_hash'] = 'UPPER' === $hash ? strtoupper( AB_MCP_Site_Mode::notice_hash() ) : $hash;
		}

		self::assertSame( 'mode_stale', self::notice_of( $this->post( 'handle_site_mode', $post ) ) );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
		self::assertNull( AB_MCP_Site_Mode::confirmation() );
		self::assertSame( array(), AB_MCP_Site_Mode::history() );
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

	public function testTheCardSaysWhatEachModeOpens(): void {
		AB_MCP_Settings::install_defaults();

		$x    = $this->xpath( $this->admin( 'mode_card_html' ) );
		$lead = trim( $x->query( '//*[contains(@class, "ab-mode__lead")]' )->item( 0 )->textContent );
		self::assertSame( 'Assistants can read content, media, terms, comments, settings and the structure of the site. The tools marked Mighty that read code, files, the database, logs or credentials run only in Full, like every tool that creates, changes or deletes — whatever their switch and the access level of the connection say.', $lead );
		self::assertSame( 1, $x->query( '//*[contains(@class, "ab-mode__lead")]' )->length );
		self::assertSame( 0, $x->query( '//*[contains(@class, "ab-mode__mighty")] | //section[@id="ab-mode"]//code' )->length, 'No list of Mighty tools that would run in Read: none does.' );

		AB_MCP_Site_Mode::switch_to_full( 3 );
		$lead = $this->xpath( $this->admin( 'mode_card_html' ) )->query( '//*[contains(@class, "ab-mode__lead")]' )->item( 0 )->textContent;
		self::assertStringContainsString( 'create, change and delete, and read code, files, the database, logs and credentials', $lead );
	}

	public function testAnAddOnCompletesWhatTheCardSaysReadReads(): void {
		// Pro reads users and shop data in Read too; its sentence follows the
		// list, escaped, and only in Read. The filter gets the registry, so the
		// sentence can follow what this site really runs.
		AB_MCP_Settings::install_defaults();
		$seen = null;
		add_filter(
			'ab_mcp_read_mode_reads_also',
			static function ( $also, $registry ) use ( &$seen ) {
				$seen = $registry;
				return ' With <Pro> they also read users. ';
			},
			10,
			2
		);

		$x    = $this->xpath( $this->admin( 'mode_card_html' ) );
		$lead = trim( $x->query( '//*[contains(@class, "ab-mode__lead")]' )->item( 0 )->textContent );
		self::assertStringEndsWith( 'access level of the connection say. With <Pro> they also read users.', $lead );
		self::assertInstanceOf( AB_MCP_Tool_Registry::class, $seen );
		self::assertStringContainsString( 'With &lt;Pro&gt; they also read users.', $this->admin( 'mode_card_html' ), 'Escaped.' );

		AB_MCP_Site_Mode::switch_to_full( 3 );
		self::assertStringNotContainsString( 'they also read users', $this->admin( 'mode_card_html' ), 'Read only.' );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function switchNotices(): array {
		return array(
			'to Read' => array( 'mode_read', 'Read is on. Assistants can read content, media, terms, comments, settings and the structure of the site; every tool that creates, changes or deletes, and every tool marked Mighty that reads code, files, the database, logs or credentials, is refused.' ),
			'to Full' => array( 'mode_full', 'Full is on. Assistants can now create, change and delete, and read code, files, the database, logs and credentials, through the tools that are switched on.' ),
		);
	}

	#[DataProvider( 'switchNotices' )]
	public function testTheNoticeAfterASwitchSaysWhatTheModeOpens( string $notice, string $text ): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_GET                   = array( 'ab_notice' => $notice );

		self::assertStringContainsString( $text, $this->admin( 'notice' ) );
	}

	public function testTheControlNextToTheTitleIsRealAndNeverTicksTheBox(): void {
		$x      = $this->xpath( $this->admin( 'mode_card_html', array() ) );
		$toggle = $x->query( '//*[contains(@class, "ab-mode__toggle")]' )->item( 0 );

		self::assertFalse( $toggle->hasAttribute( 'aria-hidden' ), 'Not decoration.' );
		self::assertSame( 'group', $toggle->getAttribute( 'role' ) );
		self::assertSame( 'Read', trim( $x->query( './/*[@aria-current="true"]', $toggle )->item( 0 )->textContent ) );
		$link = $x->query( './/a', $toggle )->item( 0 );
		self::assertSame( 'Full', trim( $link->textContent ) );
		self::assertSame( '#ab-mode-confirm', $link->getAttribute( 'href' ), 'Leads to the box.' );
		self::assertSame( 'label', $x->query( '//*[@id="ab-mode-confirm"]' )->item( 0 )->nodeName );
		self::assertFalse( $x->query( '//input[@name="confirm_full"]' )->item( 0 )->hasAttribute( 'checked' ) );
		self::assertSame( 0, $x->query( './/button', $toggle )->length, 'Nothing in it submits in Read.' );

		AB_MCP_Site_Mode::switch_to_full( 3 );
		$x      = $this->xpath( $this->admin( 'mode_card_html', array() ) );
		$toggle = $x->query( '//*[contains(@class, "ab-mode__toggle")]' )->item( 0 );
		$button = $x->query( './/button', $toggle )->item( 0 );
		self::assertSame( 'Read', trim( $button->textContent ) );
		self::assertSame( 'submit', $button->getAttribute( 'type' ) );
		self::assertSame( 'ab-mode-form', $button->getAttribute( 'form' ), 'Submits the form, whose mode is Read.' );
		self::assertSame( 'read', $x->query( '//form[@id="ab-mode-form"]//input[@name="mode"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 'Full', trim( $x->query( './/*[@aria-current="true"]', $toggle )->item( 0 )->textContent ) );
	}

	public function testTheFormCarriesTheWordingItShows(): void {
		$x = $this->xpath( $this->admin( 'mode_card_html', array() ) );

		self::assertSame( AB_MCP_Site_Mode::notice_hash(), $x->query( '//form[@id="ab-mode-form"]//input[@name="notice_hash"]' )->item( 0 )->getAttribute( 'value' ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function bandCounts(): array {
		return array(
			'Read' => array( 'read', '1 of 3 tools can run in Read' ),
			'Full' => array( 'full', '3 of 3 tools on' ),
		);
	}

	#[DataProvider( 'bandCounts' )]
	public function testTheBandCountsWhatCanRunInTheMode( string $mode, string $chip ): void {
		AB_MCP_Settings::install_defaults();
		if ( 'full' === $mode ) {
			AB_MCP_Site_Mode::switch_to_full( 3 );
		}

		$x     = $this->page();
		$chips = array();
		foreach ( $x->query( '//*[contains(@class, "ab-hero")]//*[contains(concat(" ", @class, " "), " ab-chip ")]' ) as $c ) {
			$chips[] = trim( preg_replace( '/\s+/', ' ', $c->textContent ) );
		}
		self::assertContains( $chip, $chips );
	}

	public function testAfterTheUpdateTheNoticeSaysWhatBecameOfTheSwitches(): void {
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;

		$text = $this->xpath( $this->admin( 'mode_notice' ) )->query( '//div[contains(@class, "ab-mode-notice")]' )->item( 0 )->textContent;

		self::assertStringContainsString( 'assistants can read content, media, terms, comments, settings and the structure of the site; every tool that creates, changes or deletes is refused, and so is every tool marked Mighty that reads code, files, the database, logs or credentials', $text );
		self::assertStringContainsString( 'writing tools marked Mighty that were off before this update are switched on too, and you can switch them off under Fine-tuning', $text );
		self::assertStringContainsString( 'Tools marked Mighty that only read run in Full only, and one that was switched off before this update stays off there.', $text );
	}

	public function testFineTuningSaysWhichMightyToolsStayedOffAfterTheUpdate(): void {
		$sentence = 'On this site, tools marked Mighty that only read and were switched off before the update that brought the modes stay off in Full until you switch them on here.';
		AB_MCP_Settings::install_defaults();
		self::assertStringNotContainsString( $sentence, $this->admin( 'capabilities_card_html', array(), array() ), 'Not on a new site.' );

		ab_test_reset();
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();
		self::assertStringContainsString( $sentence, $this->admin( 'capabilities_card_html', array(), array() ) );

		AB_MCP_Settings::set_tool_state( array() );
		self::assertStringNotContainsString( $sentence, $this->admin( 'capabilities_card_html', array(), array() ), 'Saved: the rule of before is gone.' );
	}

	public function testTheNoticeAndTheBoxSayWhatWasDecided(): void {
		self::assertSame( 'Full lets AI assistants create, change and delete content, files, settings and code on this live site. Switching it on can be destructive and is at your own risk. Make sure you have a current backup.', AB_MCP_Site_Mode::notice_text() );
		self::assertSame( 'I understand that switching to Full can be destructive and is at my own risk, and I have a current backup.', AB_MCP_Site_Mode::checkbox_text() );
	}

	public function testInFullTheCardWarnsSaysSinceWhenAndSwitchesBackWithoutABox(): void {
		$record = $this->confirm_full();
		$x      = $this->xpath( $this->admin( 'mode_card_html' ) );
		$card   = $x->query( '//section[@id="ab-mode"]' )->item( 0 );

		self::assertStringContainsString( 'ab-mode--full', $card->getAttribute( 'class' ), 'The warning colour.' );
		self::assertSame( 'Full', trim( $x->query( './/*[contains(@class, "ab-mode__word")]', $card )->item( 0 )->textContent ) );
		self::assertSame( 'Full since ' . wp_date( 'Y-m-d H:i', $record['time'] ) . ', confirmed by user3', trim( $x->query( './/*[contains(@class, "ab-mode__since")]', $card )->item( 0 )->textContent ) );
		self::assertSame( 0, $x->query( './/input[@name="confirm_full"]', $card )->length );
		self::assertSame( 'read', $x->query( './/input[@name="mode"]', $card )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 'Switch back to Read', trim( $x->query( './/form//button[@type="submit"]', $card )->item( 0 )->textContent ) );
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

	public function testTheReadmeSaysBeforeTheUpdateWhatItChanges(): void {
		// wordpress.org shows the Upgrade Notice on the plugin and update
		// screens before the update, at most 300 characters of it.
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		self::assertSame( 1, preg_match( '/\n== Upgrade Notice ==\n\n= 4\.4\.0 =\n([^\n]+)\n/', $readme, $m ) );
		self::assertLessThanOrEqual( 300, mb_strlen( $m[1] ) );
		self::assertStringContainsString( 'only reads', $m[1] );
		self::assertStringContainsString( 'switches to Full at the top of Settings → AlphaBridge MCP', $m[1] );
		self::assertStringContainsString( "own risk", $m[1] );
		self::assertStringContainsString( 'In Full, writing Mighty tools are on, also ones that were off before.', $m[1], 'And what Full does with the switches saved before.' );
		self::assertStringNotContainsString( 'credentials', $m[1], 'No promise that ordinary readers never meet one.' );
		self::assertStringNotContainsString( 'such as `wp_get_user_meta`', $readme, 'It reads profile fields, not code, files, the database, logs or credentials.' );
		self::assertStringContainsString( 'a Mighty tool that only reads and was switched off stays off in Full', $readme, 'And what became of the switches saved before.' );
		self::assertStringNotContainsString( 'read, list and search, and every tool', $readme, 'No longer «Read reads everything».' );
		$intro = substr( $readme, 0, (int) strpos( $readme, '== Changelog ==' ) );
		self::assertStringContainsString( 'and so is every tool marked Mighty that reads code, files, the database, logs or credentials', $intro );
	}

	public function testTheNoticesAfterSwitching(): void {
		foreach ( array( 'mode_full' => 'notice-success', 'mode_read' => 'notice-success', 'mode_unconfirmed' => 'notice-error', 'mode_stale' => 'notice-error', 'mode_unknown' => 'notice-error', 'mode_notice_dismissed' => 'notice-success' ) as $key => $class ) {
			$_GET = array( 'ab_notice' => $key );
			self::assertStringContainsString( $class, $this->admin( 'notice' ), $key );
		}
	}
}
