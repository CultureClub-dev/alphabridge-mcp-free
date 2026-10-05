<?php
/**
 * The main switch «Write access for AI assistants»: off out of the box, on
 * only on purpose. The slugs stay those of 4.4.0: 'read' is write access off,
 * 'full' write access on.
 *
 * What must hold:
 *
 * - A new installation and one updated from before 4.4.0 both start with
 *   write access off. The read-only switch of earlier versions is gone after
 *   the update; administrators see a notice about the change once, until
 *   one of them dismisses it or switches.
 * - With write access off every tool the registry does not classify as
 *   reading is refused, wp_undo and unknown tools included, and so is every
 *   reading tool marked Mighty (the readers of code, files, the database,
 *   logs or credentials), whatever its switch and the token's scope say. Each
 *   answer says why, names the way (who switches, where, at the site owner's
 *   own risk), links to the switch and asks the assistant to pass it on and
 *   try again. The refused tools stay listed.
 * - With write access on every switched-on tool runs, the powerful ones
 *   included; the fine-tuning switches single tools and groups off again.
 *   Switching write access on — on the page or by code — switches every tool
 *   on, and add-ons switch their items on through ab_mcp_reset_switches; an
 *   update alone resets nothing, and switching off keeps the switches.
 * - Only an administrator switches, with the form's own nonce; on only with
 *   the box under the notice (version 2) ticked and for the notice in force,
 *   in the wording the form showed. Who, when, which notice in which
 *   wording, which plugin version and how (settings page or code) is
 *   recorded in the option, in the history and in the log; off needs no box
 *   and keeps the record. A confirmation of notice version 1 (4.4.0) stays
 *   valid. «Clear log» leaves the history alone.
 * - A site that was in Full under 4.4.0 and saved no switches since keeps
 *   the rule for switches saved before the modes until write access is
 *   switched on again: a powerful tool that only reads keeps what it had, a
 *   saved off, and without a saved switch the old default, off — unless it
 *   is marked Mighty only since the site mode ('mighty_since').
 * - The switch is the first thing on the settings page, a button with role
 *   switch; its card says for whom it holds, what off and on mean (an add-on
 *   adds what its tools read), and with write access on since when and by
 *   whom; the band counts only what can run.
 * - The server instructions say in one sentence that write access is off,
 *   whatever a filter does.
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

	/**
	 * A site updated from before the modes that went to Full under 4.4.0,
	 * whose switch did not reset anything, and then got this version: the
	 * update keeps the mode and the switches as they are.
	 */
	private static function full_under_440(): void {
		AB_MCP_Settings::set( 'site_mode', 'full' );
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
				self::assertTrue( $res, $name . ' reads and is not Mighty, so it runs with write access off.' );
				++$readers;
				continue;
			}
			self::assertInstanceOf( WP_Error::class, $res, $name . ' writes or is Mighty, so write access off refuses it.' );
			self::assertSame( 'ab_mcp_read_mode', $res->get_error_code(), $name );
			self::assertStringContainsString( 'Direct link: https://example.test/wp-admin/options-general.php?page=alphabridge-mcp#ab-mode', $res->get_error_message() );
			if ( AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
				$mighty[] = $name;
				self::assertStringContainsString( 'the tool "' . $name . '" did not run: it is one of the reading tools that run only with write access on', $res->get_error_message() );
				continue;
			}
			++$writers;
			self::assertStringContainsString( 'the tool "' . $name . '" did not run: it changes the site', $res->get_error_message() );
		}
		self::assertGreaterThan( 10, $writers );
		self::assertGreaterThan( 10, $readers );
		self::assertSame( array( 'wp_get_user_meta' ), $mighty, 'The Mighty reader of this plugin.' );
	}

	public function testTheRefusalNamesWhoSwitchesWhereAndTheRisk(): void {
		$way = ' To allow it, an administrator can switch on write access at the top of Settings → AlphaBridge MCP and confirm the notice there; that is at the site owner\'s own risk, and a current backup is advised.'
			. ' Direct link: https://example.test/wp-admin/options-general.php?page=alphabridge-mcp#ab-mode'
			. ' Pass this on to the person you are working for in a friendly way, with the steps and the link, and try again once it is done; do not look for a way around it.';
		self::assertSame(
			'Write access is off on this site, so the tool "wp_update_post" did not run: it changes the site, and with write access off AI assistants only read.' . $way,
			AB_MCP_Site_Mode::refusal( 'wp_update_post' )->get_error_message()
		);
		self::assertSame(
			'Write access is off on this site, so the tool "wp_db_query" did not run: it is one of the reading tools that run only with write access on.' . $way,
			AB_MCP_Site_Mode::refusal( 'wp_db_query', self::mighty_readers()['wp_db_query'] )->get_error_message()
		);
		self::assertSame( AB_MCP_Site_Mode::refusal( 'wp_frobnicate' )->get_error_message(), AB_MCP_Site_Mode::refusal( 'wp_frobnicate', array( 'dangerous' => true ) )->get_error_message(), 'A Mighty tool that writes is refused because it writes.' );
		self::assertStringNotContainsString( 'Mighty', AB_MCP_Site_Mode::refusal( 'wp_db_query', self::mighty_readers()['wp_db_query'] )->get_error_message(), 'The answer names what the person sees, not the internal mark.' );
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
			self::assertStringContainsString( '"' . $name . '" did not run: it is one of the reading tools that run only with write access on', $res->get_error_message() );
			self::assertStringContainsString( 'an administrator can switch on write access at the top of Settings → AlphaBridge MCP', $res->get_error_message(), 'The answer names the way.' );
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
		self::assertStringContainsString( 'Write access is off', $last['message'] );
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

		self::full_under_440();
		self::scope( 'full' );
		foreach ( self::mighty_readers() as $name => $def ) {
			$res = AB_MCP_Security::authorize( $name, $def );
			self::assertInstanceOf( WP_Error::class, $res, $name . ' keeps its off in Full.' );
			self::assertSame( 'ab_mcp_tool_disabled', $res->get_error_code(), $name );
			self::assertStringContainsString( 'Fine-tuning', $res->get_error_message(), 'The answer names the way.' );
		}
		self::assertTrue( AB_MCP_Security::authorize( 'wp_delete_post', array( 'dangerous' => true ) ), 'A writing Mighty tool follows Full.' );

		// Switching write access on, here or on the page, ends the rule of
		// before: a switch from off to on, so off first on this site that is
		// on already. A call while it is on changes nothing.
		AB_MCP_Site_Mode::switch_to_full( 3 );
		self::assertSame( 'ab_mcp_tool_disabled', AB_MCP_Security::authorize( 'wp_db_query', self::mighty_readers()['wp_db_query'] )->get_error_code(), 'Already on: nothing switched.' );
		AB_MCP_Site_Mode::switch_to_read( 3 );
		AB_MCP_Site_Mode::switch_to_full( 3 );
		foreach ( self::mighty_readers() as $name => $def ) {
			self::assertTrue( AB_MCP_Security::authorize( $name, $def ), $name . ' is on once write access is switched on.' );
		}
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

		self::full_under_440();
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
		self::full_under_440();
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

		self::full_under_440();
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
		self::assertStringStartsWith( 'WRITE ACCESS IS OFF: ', $sentence );
		self::assertStringContainsString( 'until an administrator switches on write access at the top of Settings → AlphaBridge MCP (https://example.test/wp-admin/options-general.php?page=alphabridge-mcp#ab-mode)', $sentence );
		self::assertStringContainsString( 'every tool that creates, changes or deletes, and every reading tool that runs only with write access on (the readers of code, files, the database, logs or credentials), is refused', $sentence, 'Says that the readers of code, files, the database, logs or credentials wait for write access too.' );
		self::assertStringNotContainsStringIgnoringCase( 'mighty', $sentence, 'No marking that tools/list does not show.' );
		self::assertStringContainsString( 'when the person asks for a change, tell them that kindly and where the switch is', $sentence, 'And that the assistant leads there.' );
		self::assertStringNotContainsString( 'no code, files, database, logs or credentials', $sentence, 'Not more than the switch keeps: ordinary readers may meet a credential a guard does not recognise.' );
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
		self::assertStringContainsString( 'Write access (Full) switched on by user3 (user 3) on the settings page', $log[0]['message'] );
		self::assertStringContainsString( 'notice version ' . AB_MCP_Site_Mode::NOTICE_VERSION . ' confirmed', $log[0]['message'] );
		self::assertStringContainsString( 'wording sha256 ' . $record['notice_hash'], $log[0]['message'] );
		self::assertStringContainsString( 'plugin ' . AB_MCP_VERSION, $log[0]['message'] );
		self::assertLessThanOrEqual( 300, mb_strlen( $log[0]['message'] ), 'The log clips at 300 characters; nothing of it is cut.' );
		self::assertStringEndsWith( 'locale en_US; every switch on.', $log[0]['message'] );
		self::assertSame( array( array( 'full', 3 ) ), $changed );
		self::assertSame( array( array( 'mode' => 'full' ) + $record ), AB_MCP_Site_Mode::history(), 'And in the history.' );
	}

	public function testTheVersionOfTheNoticeIsTiedToItsEnglishWording(): void {
		// The hash of the English notice and box per version of the notice.
		// Changing either text without raising NOTICE_VERSION fails here: add
		// the new version with the hash of its wording, never edit an old one.
		$hashes = array(
			'1' => 'e63a129b792c19f2ffa0625d200070fa821bd84ba172150a7c24264399a3b310',
			'2' => '9d40c270e3b9d177b5288343d51db17690e5d39ee3baf2620e7c5051d9f083e6',
		);

		self::assertSame( '2', AB_MCP_Site_Mode::NOTICE_VERSION, 'The notice of write access is version 2.' );
		self::assertArrayHasKey( AB_MCP_Site_Mode::NOTICE_VERSION, $hashes );
		self::assertSame( $hashes[ AB_MCP_Site_Mode::NOTICE_VERSION ], AB_MCP_Site_Mode::notice_hash() );
	}

	public function testFullSetByCodeIsRecordedAsSuchAndNotAsConfirmed(): void {
		$record = AB_MCP_Site_Mode::switch_to_full( 3 );

		self::assertSame( 'code', $record['source'] );
		self::assertArrayNotHasKey( 'notice_text', $record, 'Nobody saw a notice.' );
		self::assertArrayNotHasKey( 'notice_hash', $record );
		self::assertSame( 'Write access on since ' . wp_date( 'Y-m-d H:i', $record['time'] ) . ' · set by code, not confirmed on this page · every connection keeps its access level', AB_MCP_Site_Mode::full_since_text() );
		$log = self::mode_log();
		self::assertStringContainsString( 'by code, without the box on the settings page; notice version 2 not confirmed', end( $log )['message'] );
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
		self::assertStringContainsString( 'Write access switched off (Read) by user3 (user 3)', end( $log )['message'] );
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
		self::assertSame( 'Write access for AI assistants', trim( $x->query( './/h2', $card )->item( 0 )->textContent ) );
		self::assertSame( 'https://example.test/wp-admin/admin-post.php', $form->getAttribute( 'action' ) );
		self::assertSame( 'post', $form->getAttribute( 'method' ) );
		$field = static fn( string $name ) => $x->query( './/input[@name="' . $name . '"]', $form )->item( 0 );
		self::assertSame( 'nonce-ab_mcp_site_mode', $field( '_wpnonce' )->getAttribute( 'value' ) );
		self::assertSame( 'ab_mcp_site_mode', $field( 'action' )->getAttribute( 'value' ) );
		self::assertSame( 'full', $field( 'mode' )->getAttribute( 'value' ) );
		self::assertSame( '2', $field( 'notice_version' )->getAttribute( 'value' ), 'The form carries the notice in force, version 2.' );
		self::assertSame( AB_MCP_Site_Mode::NOTICE_VERSION, $field( 'notice_version' )->getAttribute( 'value' ) );

		$box = $field( 'confirm_full' );
		self::assertSame( 'checkbox', $box->getAttribute( 'type' ) );
		self::assertSame( '1', $box->getAttribute( 'value' ) );
		self::assertFalse( $box->hasAttribute( 'checked' ), 'Not ticked in advance.' );
		self::assertTrue( $box->hasAttribute( 'required' ), 'Without JavaScript the browser asks for it.' );
		self::assertSame( AB_MCP_Site_Mode::checkbox_text(), trim( $box->parentNode->textContent ) );
		$dialog = $x->query( './/*[@id="ab-mode-confirm"]', $form )->item( 0 );
		self::assertNotNull( $dialog, 'The window the switch opens is the form itself: without JavaScript it stands in the card.' );
		self::assertFalse( $dialog->hasAttribute( 'hidden' ), 'Visible without JavaScript.' );
		self::assertSame( 'Switch on write access?', trim( $x->query( './/*[@id="' . $dialog->getAttribute( 'aria-labelledby' ) . '"]' )->item( 0 )->textContent ) );
		self::assertSame( AB_MCP_Site_Mode::notice_text(), trim( $x->query( './/*[@id="' . $dialog->getAttribute( 'aria-describedby' ) . '"]' )->item( 0 )->textContent ) );
		self::assertSame( 'Switch on', trim( $x->query( './/button[@type="submit"]', $dialog )->item( 0 )->textContent ) );
		$cancel = $x->query( './/button[contains(@class, "ab-mode__cancel")]', $dialog )->item( 0 );
		self::assertSame( 'Cancel', trim( $cancel->textContent ) );
		self::assertSame( 'button', $cancel->getAttribute( 'type' ), 'Never submits.' );
		self::assertTrue( $cancel->hasAttribute( 'hidden' ), 'Only the window has something to cancel; admin.js shows it.' );
	}

	public function testTheSwitchIsAButtonWithRoleSwitch(): void {
		$x  = $this->xpath( $this->admin( 'mode_card_html' ) );
		$sw = $x->query( '//section[@id="ab-mode"]//button[contains(@class, "ab-power")]' );

		self::assertSame( 1, $sw->length );
		$sw = $sw->item( 0 );
		self::assertSame( 'switch', $sw->getAttribute( 'role' ) );
		self::assertSame( 'false', $sw->getAttribute( 'aria-checked' ) );
		self::assertSame( 'submit', $sw->getAttribute( 'type' ), 'Without JavaScript it sends the form, and the browser asks for the box.' );
		self::assertSame( 'ab-mode-form', $sw->getAttribute( 'form' ) );
		self::assertSame( 'ab-mode-title', $sw->getAttribute( 'aria-labelledby' ), 'Its name is the title of the card.' );
		self::assertSame( 'ab-mode-confirm', $sw->getAttribute( 'aria-controls' ), 'It opens the window.' );
		self::assertSame( 'dialog', $sw->getAttribute( 'aria-haspopup' ) );
		self::assertSame( 'false', $sw->getAttribute( 'aria-expanded' ) );
		self::assertSame( array( 'On', 'Off' ), array_map( static fn( $n ): string => trim( $n->textContent ), iterator_to_array( $x->query( './/*[contains(@class, "ab-power__word")]', $sw ) ) ) );
		self::assertSame( 1, $x->query( './/*[contains(@class, "ab-power__knob")]/*[local-name()="svg"][@aria-hidden="true"]', $sw )->length, 'The power symbol.' );

		AB_MCP_Site_Mode::switch_to_full( 3 );
		$x  = $this->xpath( $this->admin( 'mode_card_html' ) );
		$sw = $x->query( '//button[contains(@class, "ab-power")]' )->item( 0 );
		self::assertSame( 'true', $sw->getAttribute( 'aria-checked' ) );
		self::assertSame( 'ab-mode-form', $sw->getAttribute( 'form' ) );
		self::assertFalse( $sw->hasAttribute( 'aria-controls' ), 'On, it opens nothing: one click switches off.' );
		self::assertSame( 'read', $x->query( '//form[@id="ab-mode-form"]//input[@name="mode"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 0, $x->query( '//input[@name="confirm_full"]' )->length, 'No box to switch off.' );
	}

	public function testTheCardSaysWhatEachModeOpens(): void {
		AB_MCP_Settings::install_defaults();

		$x = $this->xpath( $this->admin( 'mode_card_html' ) );
		self::assertSame( 'Off: AI assistants only read. For anything they should change, you flip the switch.', trim( $x->query( '//*[contains(@class, "ab-mode__status")]' )->item( 0 )->textContent ) );
		self::assertSame( 'Applies to every connection: Claude, ChatGPT, Cursor and all others.', trim( $x->query( '//*[contains(@class, "ab-mode__scope")]' )->item( 0 )->textContent ), 'Not only Claude.' );
		$boxes = array();
		foreach ( $x->query( '//*[contains(concat(" ", @class, " "), " ab-mode__box ")]' ) as $b ) {
			$boxes[] = trim( preg_replace( '/\s+/', ' ', $x->query( './/strong', $b )->item( 0 )->textContent ) ) . ' | ' . trim( $x->query( './/span', $b )->item( 0 )->textContent );
		}
		self::assertSame(
			array(
				'Off: read only | AI assistants read content, media and settings and advise you.',
				'On: full power | AI assistants read and write: posts, pages, media, terms and comments.',
			),
			$boxes,
			'The free plugin names only what its own tools write.'
		);
		self::assertSame( 0, $x->query( '//*[contains(@class, "ab-mode__since")]' )->length );
		self::assertSame( 0, $x->query( '//section[@id="ab-mode"]//code' )->length, 'No list of tools.' );

		AB_MCP_Site_Mode::switch_to_full( 3 );
		$x = $this->xpath( $this->admin( 'mode_card_html' ) );
		self::assertSame( 'On: full power. Connected AI assistants read and write on this site.', trim( $x->query( '//*[contains(@class, "ab-mode__status")]' )->item( 0 )->textContent ) );
		self::assertStringContainsString( 'ab-mode--full', $x->query( '//section[@id="ab-mode"]' )->item( 0 )->getAttribute( 'class' ), 'The deep violet card.' );
	}

	public function testWithProTheOnBoxNamesWhatProWrites(): void {
		add_filter( 'ab_mcp_site_edition', static fn(): string => 'pro' );

		$x = $this->xpath( $this->admin( 'mode_card_html' ) );
		self::assertSame( 'AI assistants read and write: posts, pages, plugins, themes, code and the database.', trim( $x->query( '//*[contains(@class, "ab-mode__writes")]' )->item( 0 )->textContent ) );
	}

	public function testAnAddOnCompletesWhatTheCardSaysReadReads(): void {
		// Pro reads users and shop data with write access off too; its
		// sentence follows the box «Off: read only», escaped, in both states.
		// The filter gets the registry, so the sentence can follow what this
		// site really runs.
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

		$x     = $this->xpath( $this->admin( 'mode_card_html' ) );
		$reads = trim( $x->query( '//*[contains(@class, "ab-mode__reads")]' )->item( 0 )->textContent );
		self::assertSame( 'AI assistants read content, media and settings and advise you. With <Pro> they also read users.', $reads );
		self::assertInstanceOf( AB_MCP_Tool_Registry::class, $seen );
		self::assertStringContainsString( 'With &lt;Pro&gt; they also read users.', $this->admin( 'mode_card_html' ), 'Escaped.' );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function switchNotices(): array {
		return array(
			'to Read' => array( 'mode_read', 'Write access is off. AI assistants only read now: content, media, terms, comments, settings and the structure of the site; every tool that creates, changes or deletes, and every reading tool noted “only with write access”, is refused.' ),
			'to Full' => array( 'mode_full', 'Write access is on. AI assistants can now create, change and delete through every tool, and every tool is switched on; you can switch single tools off under Fine-tuning.' ),
		);
	}

	#[DataProvider( 'switchNotices' )]
	public function testTheNoticeAfterASwitchSaysWhatTheModeOpens( string $notice, string $text ): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_GET                   = array( 'ab_notice' => $notice );

		self::assertStringContainsString( $text, $this->admin( 'notice' ) );
	}

	public function testTheOldReadFullControlIsGone(): void {
		$x = $this->xpath( $this->admin( 'mode_card_html', array() ) );

		self::assertSame( 0, $x->query( '//*[contains(@class, "ab-mode__toggle") or contains(@class, "ab-mode__word") or contains(@class, "ab-mode__opt")]' )->length );
		self::assertStringNotContainsString( 'Mode:', $this->admin( 'mode_card_html', array() ) );
		self::assertStringNotContainsString( 'Full', $x->query( '//section[@id="ab-mode"]' )->item( 0 )->textContent, 'The page says write access, not Full.' );
	}

	public function testTheFormCarriesTheWordingItShows(): void {
		$x = $this->xpath( $this->admin( 'mode_card_html', array() ) );

		self::assertSame( AB_MCP_Site_Mode::notice_hash(), $x->query( '//form[@id="ab-mode-form"]//input[@name="notice_hash"]' )->item( 0 )->getAttribute( 'value' ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function bandCounts(): array {
		return array(
			'Read' => array( 'read', '1 of 3 tools can run with write access off' ),
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

		self::assertStringContainsString( 'Since this update every site starts with write access off: AI assistants can read content, media, terms, comments, settings and the structure of the site; every tool that creates, changes or deletes is refused', $text );
		self::assertStringContainsString( 'Switching on write access switches every tool on; afterwards you can switch single tools off under Fine-tuning.', $text );
		self::assertStringNotContainsString( 'Mighty', $text );
		self::assertStringNotContainsString( 'Full', $text );
	}

	public function testFineTuningSaysWhichMightyToolsStayedOffAfterTheUpdate(): void {
		// The fine-tuning names what these tools read; the mark «Mighty» is
		// no longer shown there. Only where the rule of before still applies:
		// on, and not switched on since.
		$sentence = 'On this site, tools that read code, files, the database, logs or credentials and were switched off before the update that brought write access stay off until you switch them on here or switch on write access again.';
		AB_MCP_Settings::install_defaults();
		self::assertStringNotContainsString( $sentence, $this->admin( 'capabilities_card_html', array(), array() ), 'Not on a new site.' );

		ab_test_reset();
		self::before_the_update();
		AB_MCP_Settings::maybe_upgrade();
		self::assertStringNotContainsString( $sentence, $this->admin( 'capabilities_card_html', array(), array() ), 'Not while write access is off: switching it on switches every tool on.' );

		self::full_under_440();
		self::assertStringContainsString( $sentence, $this->admin( 'capabilities_card_html', array(), array() ) );

		AB_MCP_Settings::set_tool_state( array() );
		self::assertStringNotContainsString( $sentence, $this->admin( 'capabilities_card_html', array(), array() ), 'Saved: the rule of before is gone.' );
	}

	public function testTheNoticeAndTheBoxSayWhatWasDecided(): void {
		self::assertSame( 'With write access, your AI assistants work directly on your live website. Changes take effect immediately: they can create, change and also delete content, files, settings and code, and not everything can be undone. You switch this on at your own risk. A current backup keeps you on the safe side.', AB_MCP_Site_Mode::notice_text() );
		self::assertSame( 'Understood: changes take effect immediately, I switch on write access at my own risk and I have a current backup.', AB_MCP_Site_Mode::checkbox_text() );
		self::assertSame( 'Write access off (read only)', AB_MCP_Site_Mode::label( AB_MCP_Site_Mode::READ ) );
		self::assertSame( 'Write access on (full power)', AB_MCP_Site_Mode::label( AB_MCP_Site_Mode::FULL ) );
	}

	public function testInFullTheCardWarnsSaysSinceWhenAndSwitchesBackWithoutABox(): void {
		$record = $this->confirm_full();
		$x      = $this->xpath( $this->admin( 'mode_card_html' ) );
		$card   = $x->query( '//section[@id="ab-mode"]' )->item( 0 );

		self::assertStringContainsString( 'ab-mode--full', $card->getAttribute( 'class' ), 'The deep violet card.' );
		self::assertSame( 'Write access on since ' . wp_date( 'Y-m-d H:i', $record['time'] ) . ' · confirmed by user3 · every connection keeps its access level', trim( $x->query( './/*[contains(@class, "ab-mode__since")]', $card )->item( 0 )->textContent ) );
		self::assertSame( 0, $x->query( './/input[@name="confirm_full"]', $card )->length );
		self::assertSame( 'read', $x->query( './/input[@name="mode"]', $card )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 'ab-mode-form', $x->query( './/button[@role="switch"]', $card )->item( 0 )->getAttribute( 'form' ), 'One click on the switch switches off.' );
	}

	public function testFullSetByCodeSaysThatNothingWasConfirmed(): void {
		self::full();

		self::assertSame( 'Write access is on; no confirmation on this page is recorded for it.', AB_MCP_Site_Mode::full_since_text() );
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
		self::assertStringContainsString( 'an administrator switches on write access at the top of Settings → AlphaBridge MCP', $notice->textContent );
		self::assertStringContainsString( 'at the site owner\'s own risk', $notice->textContent );
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
		self::assertSame( 1, preg_match( '/\n== Upgrade Notice ==\n\n= 4\.5\.0 =\n([^\n]+)\n\n= 4\.4\.0 =\n([^\n]+)\n/', $readme, $m ) );
		self::assertLessThanOrEqual( 300, mb_strlen( $m[1] ) );
		self::assertStringContainsString( 'main switch «Write access for AI assistants», for every connection', $m[1] );
		self::assertStringContainsString( 'Switching it on switches every tool on', $m[1] );
		self::assertStringContainsString( 'a confirmation of 4.4.0 stays valid', $m[1] );
		self::assertStringContainsString( 'New connections start with the full access level', $m[1] );
		self::assertLessThanOrEqual( 300, mb_strlen( $m[2] ), 'The notice of 4.4.0 stays as it was.' );
		self::assertStringNotContainsString( 'such as `wp_get_user_meta`', $readme, 'It reads profile fields, not code, files, the database, logs or credentials.' );
		self::assertStringNotContainsString( 'read, list and search, and every tool', $readme, 'No longer «Read reads everything».' );
		$intro = substr( $readme, 0, (int) strpos( $readme, '== Changelog ==' ) );
		self::assertStringContainsString( 'and so is every reading tool noted «only with write access» under Fine-tuning: in this plugin that is the reader of user profile fields', $intro );
		self::assertStringContainsString( 'The main switch «Write access for AI assistants» at the top of Settings → AlphaBridge MCP holds for every connection', $intro );
		self::assertStringNotContainsString( 'the mode Read', $intro, 'The description says write access, not Read or Full.' );
		self::assertStringNotContainsString( 'Mighty', $intro );
		self::assertSame( 1, preg_match( '/^Stable tag: ([0-9.]+)$/m', $readme, $tag ) );
		self::assertTrue( version_compare( $tag[1], '4.4.0', '>=' ) && version_compare( $tag[1], (string) ( preg_match( '/^ \* Version:\s+([0-9.]+)/m', (string) file_get_contents( dirname( __DIR__ ) . '/alphabridge-mcp.php' ), $v ) ? $v[1] : '0' ), '<=' ), 'The stable tag moves with the release, not with the code: it names a published version and is never ahead of the code.' );
		self::assertSame( 1, preg_match( '/\n== Changelog ==\n\n= 4\.5\.2 =\n(?:[^\n]+\n)+\n= 4\.5\.1 =\n(?:[^\n]+\n)+\n= 4\.5\.0 =\n/', $readme ) );
	}

	/**
	 * The head of readme.txt is what the plugin directory shows first: the
	 * name, and the short description, cut at 150 characters. An «&» in the
	 * name comes out as «&amp;».
	 */
	public function testTheReadmeHeadFitsTheDirectory(): void {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		self::assertSame( 1, preg_match( '/^=== (.+) ===\n/', $readme, $name ) );
		self::assertStringNotContainsString( '&', $name[1] );
		self::assertSame( 1, preg_match( '/\nLicense URI: [^\n]+\n\n([^\n]+)\n\n== Description ==\n/', $readme, $short ) );
		self::assertLessThanOrEqual( 150, mb_strlen( html_entity_decode( trim( $short[1] ), ENT_QUOTES ) ) );
	}

	/**
	 * The switch turns the tools on; each connection keeps its access level
	 * (AB_MCP_Security::authorize() checks the level after the switch), as the
	 * settings page says once write access is on.
	 */
	public function testTheReadmesSayEachConnectionKeepsItsAccessLevel(): void {
		foreach ( array( 'readme.txt', 'README.md' ) as $file ) {
			$text = (string) preg_replace( '/\s+/', ' ', (string) file_get_contents( dirname( __DIR__ ) . '/' . $file ) );
			self::assertMatchesRegularExpression( '/every tool (is )?on; each connection keeps its access level/', $text, $file );
			self::assertStringNotContainsString( 'every tool is on, for every connection', $text, $file );
		}
	}

	/**
	 * The readmes describe this plugin: its only reader that waits for write
	 * access is wp_get_user_meta, which reads profile fields. Readers of code,
	 * files, the database or logs come with AlphaBridge MCP Pro, not here.
	 */
	public function testTheReadmesNameTheReadersThatWaitForWriteAccess(): void {
		$r = new AB_MCP_Tool_Registry();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && ! ( new \ReflectionClass( $class ) )->isAbstract() && method_exists( $class, 'register' ) ) {
				$class::register( $r );
			}
		}
		$waiting = array();
		foreach ( $r->all() as $name => $def ) {
			if ( AB_MCP_Tool_Registry::is_read_only( (string) $name, $def ) && ! AB_MCP_Site_Mode::runs_in_read( (string) $name, $def ) ) {
				$waiting[] = $name;
			}
		}
		self::assertGreaterThan( 35, count( $r->all() ) );
		self::assertSame( array( 'wp_get_user_meta' ), $waiting, 'A new reader that waits for write access needs its own words in the readmes.' );

		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		$intro  = substr( $readme, 0, (int) strpos( $readme, '== Changelog ==' ) );
		self::assertStringNotContainsString( 'logs or credentials', $intro, 'Free has no reader of code, files, the database, logs or credentials.' );
		self::assertStringContainsString( 'To let them create, change and delete, and read user profile fields, switch on «Write access for AI assistants»', $intro );
		self::assertStringContainsString( 'and every reading tool noted «only with write access» (in this plugin the reader of user profile fields), is refused until', $intro );
		$md = (string) preg_replace( '/\s+/', ' ', (string) file_get_contents( dirname( __DIR__ ) . '/README.md' ) );
		self::assertStringContainsString( 'and so is every reading tool noted *only with write access* (in this plugin the reader of user profile fields)', $md );
		self::assertStringNotContainsString( 'because it reads code, files, the database, logs or credentials', $md );
	}

	/**
	 * Claude asks for the site's address, and the settings page has a button
	 * to copy it; what there is not to copy is the endpoint or a token.
	 */
	public function testTheReadmeSaysWhatToCopyWhenConnectingFromClaude(): void {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		self::assertStringContainsString( "Enter your site's address and approve on your own site's login-protected consent screen. There is no endpoint or token to copy", $readme );
		self::assertStringNotContainsString( 'no address or token to copy', $readme );
		self::assertStringContainsString( "this site's address to copy", $readme, 'Screenshot 1.' );
	}

	public function testTheNoticesAfterSwitching(): void {
		foreach ( array( 'mode_full' => 'notice-success', 'mode_read' => 'notice-success', 'mode_unconfirmed' => 'notice-error', 'mode_stale' => 'notice-error', 'mode_unknown' => 'notice-error', 'mode_notice_dismissed' => 'notice-success' ) as $key => $class ) {
			$_GET = array( 'ab_notice' => $key );
			self::assertStringContainsString( $class, $this->admin( 'notice' ), $key );
		}
	}
}
