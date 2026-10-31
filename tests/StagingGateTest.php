<?php
/**
 * «Staging first»: the pre-check of a write on a site that counts as the
 * production site (AB_MCP_Staging_Gate, in AB_MCP_REST_Controller::run_tool();
 * free since 4.15.0).
 *
 * What must hold, clause by clause:
 *
 * - The gate concerns every tool is_read_only() does not count as reading,
 *   except the exempt ones: the ways back and the protection (wp_undo,
 *   wp_undo_discard, wp_set_preview_protection, wp_clear_cache), the
 *   operation of the production site (comments, the order status) and the
 *   carrying over from staging (wp_site_push, wp_site_sync). The filter
 *   ab_mcp_staging_gate_exempt adds names and cannot take a way back away.
 *   The diagnostic tools only read, so they stand outside already.
 * - Every tool the gate concerns, Pro's included, takes staging_acknowledged
 *   and staging_reminder in its schema and says so in its description;
 *   reading and exempt tools keep theirs.
 * - The setting: ask by default, for every site; a value that is none of
 *   the three reads as ask; a change takes every «no more» back.
 * - Ask: the first writing call of a connection is answered with
 *   nothing_changed and staging_hint and runs nothing, logged as held; the
 *   same call with staging_acknowledged true runs, with the gate's
 *   arguments taken out before the token and the tool; for 7 days the
 *   connection is not asked again, then the question comes back with
 *   staging_reminder week (another 7 days) or never (no more, until the
 *   setting or the role of the site changes; the settings page takes it
 *   back); never on the first answer counts as week; an acknowledgement
 *   without a held call before it counts too. A preview (apply false)
 *   counts as a write.
 * - The gate stands before the confirmation token: a tool without a way
 *   back is held first, previews second, runs third.
 * - A connection from before this version is told that this is new in
 *   4.15.0; one made after the stamp is not; the stamp is written once.
 * - The site stands outside the gate as the confirmed staging site or with
 *   a confirmed pair; with require, only when a person confirmed it on the
 *   settings page, not over the hub's route. Role production is inside.
 * - Off: nothing is held.
 * - Require: refused with staging_required, the steps and the link, logged
 *   as denied, until a person releases the connection on the settings page
 *   for 24 hours; the release ends by time or by revocation; both are in
 *   the history. A call without a token cannot be released.
 * - A call without a token (the abilities adapter) is kept by its account
 *   id and not exempt.
 * - The way without a staging site (drafts for posts and pages) is written
 *   in one place, AB_MCP_Staging, and the instructions, the prompt, the
 *   checklist and the hint take it from there.
 * - The settings page: the setting behind its nonce and manage_options,
 *   the release, the revocation and «ask again» per connection, the
 *   states, the notices, the log word.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Audit_Log;
use AB_MCP_Auth;
use AB_MCP_Confirm_Token;
use AB_MCP_Guidance;
use AB_MCP_REST_Controller;
use AB_MCP_Settings;
use AB_MCP_Staging;
use AB_MCP_Staging_Gate as Gate;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use AbTestExit;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WP_Error;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';
require_once __DIR__ . '/../includes/tools/class-tools-undo-core.php';
require_once __DIR__ . '/../includes/tools/class-tools-system.php';
require_once __DIR__ . '/../includes/tools/class-tools-shop.php';

final class StagingGateTest extends TestCase {

	const STAGING = 'https://staging.example.test';

	/** @var int Calls that reached the tool. */
	private int $runs = 0;

	/** @var array The arguments the tool last received. */
	private array $seen = array();

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		ab_test_add_user( 9 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
		$GLOBALS['ab_test_home']         = 'https://example.test';
		$_GET                            = array();
		$_POST                           = array();
		$_REQUEST                        = array();
		AB_MCP_Settings::set( 'site_mode', 'full' );
		self::scope( 'full' );
		self::hash( '' );
		$this->runs = 0;
		$this->seen = array();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		self::scope( 'full' );
		self::hash( '' );
	}

	/* ------------------------------------------------------------ helpers */

	private static function scope( string $scope ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, $scope );
	}

	private static function hash( string $hash ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_token_hash' ) )->setValue( null, $hash );
	}

	/** A connection: a token entry, authenticated for this request. Returns its hash. */
	private static function connect( string $label = 'Claude', int $user = 7 ): string {
		$token = AB_MCP_Settings::add_token( $user, $label );
		self::assertSame( $user, AB_MCP_Auth::verify_token( $token ) );
		$hash = hash( 'sha256', $token );
		self::assertSame( $hash, AB_MCP_Auth::current_token_hash() );
		return $hash;
	}

	/** A writing tool that records what reaches it. */
	private function writer( array $extra = array() ): array {
		return array_merge(
			array(
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array( 'type' => 'string' ),
						'apply' => array( 'type' => 'boolean' ),
					),
				),
				'callback'    => function ( array $args ) {
					++$this->runs;
					$this->seen = $args;
					return array( 'updated' => true );
				},
			),
			$extra
		);
	}

	/** Run a tool through run_tool(), as tools/call and the abilities adapter do. */
	private function call( string $name, array $def, array $args = array(), string $via = '' ) {
		return AB_MCP_REST_Controller::run_tool( $name, $def, $args, $via );
	}

	private static function last_log(): array {
		$log = AB_MCP_Audit_Log::recent( 1 );
		return $log[0] ?? array();
	}

	private static function entry( string $hash ): array {
		return AB_MCP_Settings::get_token_by_hash( $hash ) ?? array();
	}

	/** The registry of this plugin's tools, as the plugin registers them. */
	private static function registry(): AB_MCP_Tool_Registry {
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/class-tools-*.php' ) as $file ) {
			require_once $file;
		}
		$r = new AB_MCP_Tool_Registry();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && ! ( new \ReflectionClass( $class ) )->isAbstract() && method_exists( $class, 'register' ) ) {
				$class::register( $r );
			}
		}
		return $r;
	}

	/** @param mixed ...$args */
	private function admin( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin(), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	/** Send a form; returns how it ended. */
	private function submit( string $handler, array $post, string $nonce ): AbTestExit {
		$_POST    = array_merge( $post, array( '_wpnonce' => $nonce ) );
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin() )->$handler();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( 'The form ended without a redirect.' );
	}

	/* ------------------------------------------------------ exempt tools */

	public function testTheExemptListHasItsThreeGroupsAndTheFilterOnlyAdds(): void {
		self::assertSame( array( 'wp_undo', 'wp_undo_discard', 'wp_set_preview_protection', 'wp_clear_cache' ), Gate::WAYS_BACK );
		self::assertSame( array( 'wp_moderate_comment', 'wp_reply_comment', 'wp_wc_update_order_status' ), Gate::OPERATION );
		self::assertSame( array( 'wp_site_push', 'wp_site_sync' ), Gate::CARRY_OVER );
		self::assertSame( array_merge( Gate::WAYS_BACK, Gate::OPERATION, Gate::CARRY_OVER ), Gate::exempt_tools(), 'Without a filter: the three groups, in this order.' );

		add_filter( Gate::FILTER, static fn( array $names ): array => array_merge( $names, array( 'acme_sync_to_staging' ) ) );
		self::assertContains( 'acme_sync_to_staging', Gate::exempt_tools(), 'An add-on adds its own.' );
		self::assertContains( 'wp_moderate_comment', Gate::exempt_tools() );
		$GLOBALS['ab_test_filters'] = array();

		add_filter( Gate::FILTER, static fn(): array => array() );
		self::assertSame( Gate::WAYS_BACK, Gate::exempt_tools(), 'A filter that returns nothing still leaves the ways back exempt.' );
		$GLOBALS['ab_test_filters'] = array();

		add_filter( Gate::FILTER, static fn(): string => 'broken' );
		self::assertSame( Gate::WAYS_BACK, Gate::exempt_tools(), 'A filter that returns no list: the ways back.' );
		$GLOBALS['ab_test_filters'] = array();

		add_filter( Gate::FILTER, static fn( array $names ): array => array_values( array_diff( $names, array( 'wp_undo', 'wp_moderate_comment' ) ) ) );
		self::assertContains( 'wp_undo', Gate::exempt_tools(), 'A way back cannot be taken away.' );
		self::assertNotContains( 'wp_moderate_comment', Gate::exempt_tools(), 'The operation can, by whoever knows the site.' );
	}

	public function testEveryExemptToolIsNeverHeldEachOnItsOwn(): void {
		self::connect();
		foreach ( Gate::exempt_tools() as $name ) {
			$this->runs = 0;
			$def        = $this->writer();
			$res        = $this->call( $name, $def, array( 'title' => 'x' ) );
			self::assertSame( array( 'updated' => true ), $res, $name . ' is exempt and runs at once.' );
			self::assertSame( 1, $this->runs, $name );
			self::assertFalse( Gate::concerns( $name, $def ), $name );
		}
		foreach ( array( 'wp_update_post', 'wp_create_post', 'wp_delete_post', 'wp_site_push_x', 'acme_write' ) as $name ) {
			self::assertTrue( Gate::concerns( $name, $this->writer() ), $name . ' is a writing tool the gate concerns.' );
		}
	}

	public function testTheDiagnosticToolsOnlyReadSoTheGateNeverSeesThem(): void {
		$r = self::registry();
		foreach ( array( 'wp_connection_doctor', 'wp_site_health', 'wp_site_info', 'wp_get_error_log', 'wp_staging_status' ) as $name ) {
			$def = $r->get( $name );
			self::assertIsArray( $def, $name );
			self::assertTrue( AB_MCP_Tool_Registry::is_read_only( $name, $def ), $name . ' reads.' );
			self::assertFalse( Gate::concerns( $name, $def ), $name );
		}
	}

	public function testReadingToolsAreNeverHeldByPrefixByFlagAndByList(): void {
		self::connect();
		foreach ( array(
			'wp_list_posts'   => array(),
			'wp_get_post'     => array(),
			'wp_read_file'    => array(),
			'wp_wc_list_x'    => array(),
			'wp_wc_get_x'     => array(),
			'wp_search'       => array(),
			'wp_connection_doctor' => array(),
			'acme_report'     => array( 'readonly' => true ),
		) as $name => $extra ) {
			$this->runs = 0;
			self::assertSame( array( 'updated' => true ), $this->call( $name, $this->writer( $extra ), array( 'title' => 'x' ) ), $name );
			self::assertSame( 1, $this->runs, $name );
		}
	}

	/* ------------------------------------------------------------ schema */

	public function testEveryToolTheGateConcernsTakesTheTwoArgumentsAndSaysSo(): void {
		$r = self::registry();
		$concerned = 0;
		foreach ( $r->all() as $name => $def ) {
			$props = (array) $def['inputSchema']['properties'];
			if ( Gate::concerns( $name, $def ) ) {
				++$concerned;
				self::assertSame( Gate::schema_property(), $props[ Gate::PARAM ], $name );
				self::assertSame( Gate::reminder_property(), $props[ Gate::PARAM_REMINDER ], $name );
				self::assertStringEndsWith( Gate::description_note(), $def['description'], $name );
				self::assertSame( array( Gate::PARAM, Gate::PARAM_REMINDER ), array_slice( array_keys( $props ), -2 ), $name . ': the two come last.' );
			} else {
				self::assertArrayNotHasKey( Gate::PARAM, $props, $name . ' keeps its schema.' );
				self::assertArrayNotHasKey( Gate::PARAM_REMINDER, $props, $name );
				self::assertStringNotContainsString( 'staging_acknowledged', $def['description'], $name );
			}
		}
		self::assertGreaterThan( 40, $concerned, 'Most of the writing tools.' );
		self::assertFalse( Gate::concerns( 'wp_set_preview_protection', $r->get( 'wp_set_preview_protection' ) ) );
		self::assertArrayNotHasKey( Gate::PARAM, (array) $r->get( 'wp_set_preview_protection' )['inputSchema']['properties'] );
		self::assertArrayNotHasKey( Gate::PARAM, (array) $r->get( 'wp_staging_status' )['inputSchema']['properties'] );
		self::assertArrayNotHasKey( Gate::PARAM, (array) $r->get( 'wp_undo' )['inputSchema']['properties'] );
		self::assertArrayNotHasKey( Gate::PARAM, (array) $r->get( 'wp_wc_update_order_status' )['inputSchema']['properties'] );
		self::assertArrayHasKey( Gate::PARAM, (array) $r->get( 'wp_update_post' )['inputSchema']['properties'] );
		self::assertArrayHasKey( Gate::PARAM, (array) $r->get( 'wp_update_core' )['inputSchema']['properties'], 'Next to confirm_token.' );
	}

	public function testAProToolRegisteredOverTheCoreGetsTheSame(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->register( 'wp_write_plugin_file', array( 'description' => 'Pro', 'capability' => 'edit_plugins' ) );
		$r->register( 'wp_read_plugin_file', array( 'description' => 'Pro', 'capability' => 'edit_plugins' ) );
		self::assertArrayHasKey( Gate::PARAM, (array) $r->get( 'wp_write_plugin_file' )['inputSchema']['properties'] );
		self::assertSame( 'Pro ' . Gate::description_note(), $r->get( 'wp_write_plugin_file' )['description'] );
		self::assertSame( array(), (array) $r->get( 'wp_read_plugin_file' )['inputSchema']['properties'] );
		self::assertSame( 'Pro', $r->get( 'wp_read_plugin_file' )['description'] );
		self::assertSame( 'boolean', Gate::schema_property()['type'] );
		self::assertSame( array( 'week', 'never' ), Gate::reminder_property()['enum'] );
	}

	/* ----------------------------------------------------------- setting */

	public function testTheSettingIsAskByDefaultAndForEveryStoredNonsense(): void {
		self::assertSame( Gate::ASK, Gate::setting(), 'No key: ask, also on a site updated from 4.14.0.' );
		update_option( AB_MCP_Settings::OPT_OPTIONS, array( 'site_mode' => 'full' ) );
		self::assertSame( Gate::ASK, Gate::setting() );
		AB_MCP_Settings::set( Gate::SETTING, 'maybe' );
		self::assertSame( Gate::ASK, Gate::setting() );
		AB_MCP_Settings::set( Gate::SETTING, Gate::OFF );
		self::assertSame( Gate::OFF, Gate::setting() );
		AB_MCP_Settings::set( Gate::SETTING, Gate::REQUIRE );
		self::assertSame( Gate::REQUIRE, Gate::setting() );
		self::assertFalse( Gate::is_setting( 'on' ) );
		self::assertTrue( Gate::is_setting( 'ask' ) );
	}

	public function testSettingTheSettingRefusesNonsenseAndReportsAChange(): void {
		self::assertInstanceOf( WP_Error::class, Gate::set_setting( 'maybe' ) );
		self::assertSame( Gate::ASK, Gate::setting() );
		self::assertSame( Gate::ASK, AB_MCP_Settings::defaults()[ Gate::SETTING ], 'The default of the plugin.' );
		self::assertFalse( Gate::set_setting( Gate::ASK ), 'The default again: no change.' );
		self::assertSame( 0, Gate::set_setting( Gate::REQUIRE ), 'Changed; no «no more» to take back.' );
		self::assertSame( Gate::REQUIRE, Gate::setting() );
		self::assertFalse( Gate::set_setting( Gate::REQUIRE ) );
	}

	public function testAChangeOfTheSettingTakesEveryNoMoreBackButKeepsTheWeek(): void {
		$hash = self::connect();
		$now  = time();
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => $now - 100, Gate::FIELD_NEVER => array( 'at' => $now - 100, 'setting' => 'ask', 'role' => 'unconfirmed' ) ) );
		Gate::save_state( array( 'kind' => 'user', 'id' => '9' ), array( Gate::FIELD_ACK => $now - 100, Gate::FIELD_NEVER => array( 'at' => $now - 100 ) ) );

		self::assertFalse( Gate::set_setting( Gate::ASK ), 'Unchanged: ask is the default.' );
		self::assertArrayHasKey( Gate::FIELD_NEVER, self::entry( $hash ), 'No change, nothing taken back.' );

		self::assertSame( 2, Gate::set_setting( Gate::REQUIRE ), 'Changed, and two «no more» taken back: the connection\'s and the account\'s.' );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, self::entry( $hash ), 'The connection\'s «no more» is gone.' );
		self::assertSame( $now - 100, self::entry( $hash )[ Gate::FIELD_ACK ], 'Its week stays.' );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, Gate::user_states()[9], 'The account\'s too.' );
		self::assertSame( $now - 100, Gate::user_states()[9][ Gate::FIELD_ACK ] );
	}

	public function testAChangeOfTheRoleTakesEveryNoMoreBack(): void {
		$hash = self::connect();
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_NEVER => array( 'at' => time() ) ) );
		self::assertSame( array( 'role_changed' => false, 'pair_changed' => false ), AB_MCP_Staging::save( AB_MCP_Staging::ROLE_UNCONFIRMED, '', null, 7, AB_MCP_Staging::SOURCE_FORM ) );
		self::assertArrayHasKey( Gate::FIELD_NEVER, self::entry( $hash ), 'The role did not change.' );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_PRODUCTION, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, self::entry( $hash ), 'The role changed: asked again.' );
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_NEVER => array( 'at' => time() ) ) );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_PRODUCTION, self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertArrayHasKey( Gate::FIELD_NEVER, self::entry( $hash ), 'A pair alone is no change of the role.' );
	}

	/* --------------------------------------------------------------- ask */

	public function testTheFirstWritingCallIsHeldAndNothingRuns(): void {
		$hash = self::connect();
		$res  = $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x' ) );

		self::assertIsArray( $res );
		self::assertSame( 0, $this->runs, 'Nothing ran.' );
		self::assertTrue( $res['nothing_changed'] );
		self::assertSame( 'staging_first', $res['held'] );
		self::assertSame( 'unconfirmed', $res['staging_role'] );
		self::assertFalse( $res['asked_before'] );
		self::assertNull( $res['new_in_version'], 'A connection made under this version: nothing new to say.' );
		self::assertSame( array( 'staging_acknowledged' => true ), $res['go_ahead_with'] );
		self::assertArrayNotHasKey( 'reminder_options', $res );
		self::assertSame( Gate::hint( false, false ), $res['staging_hint'] );
		self::assertSame( 'held', self::last_log()['status'] );
		self::assertSame( 'wp_update_post', self::last_log()['tool'] );
		self::assertArrayNotHasKey( 'via', self::last_log() );
		self::assertArrayNotHasKey( Gate::FIELD_ACK, self::entry( $hash ), 'Held is not acknowledged.' );
	}

	public function testTheHintSaysWhyHowAndWhatToOfferAndHowToGoAhead(): void {
		$hint = Gate::hint( false, false );
		self::assertStringStartsWith( 'Staging first. This site counts as the production site (no confirmed staging role and no pair), so a change here is visible to visitors at once; on a staging site it is checked first and carried over once it is approved. Nothing has been changed yet.', $hint );
		self::assertStringContainsString( 'Ask the person whether to work on staging first, saying why and how: a staging copy with the host\'s staging function, a staging plugin, an existing staging site connected to this one, or drafts for posts and pages alone.', $hint );
		self::assertStringContainsString( 'Offer to set up staging now and to continue there: wp_staging_status shows where the site stands and the next step, and the prompt staging_workflow has the steps.', $hint );
		self::assertStringEndsWith( 'If the person would rather go ahead on this site, call the same tool again with the same arguments and staging_acknowledged true; this connection is then not asked again for 7 days, with the next change after that.', $hint );
		self::assertStringNotContainsString( 'New in', $hint );
		self::assertDoesNotMatchRegularExpression( '/\bPro\b|Agency|upgrade|licen[cs]e|price|checkout|https?:\/\//i', $hint, 'No advertising, no link.' );
		self::assertStringNotContainsString( 'the person was asked', $hint, 'It does not claim the question was asked.' );

		$existing = Gate::hint( true, false );
		self::assertStringStartsWith( 'New in AlphaBridge MCP 4.15.0: Staging first. Before the first change, the assistant offers to work on a staging site. This site counts as the production site', $existing );
		self::assertSame( substr( $hint, strlen( 'Staging first. ' ) ), substr( $existing, strpos( $existing, 'This site counts' ) ), 'The same question after the opening.' );

		$again = Gate::hint( false, true );
		self::assertStringStartsWith( 'Staging first, again: the week since the last answer is over. This site counts as the production site', $again );
		self::assertStringContainsString( 'Ask the person again whether to set up a staging site now and continue there, saying why and how:', $again );
		self::assertStringContainsString( 'pass the person\'s choice in staging_reminder: week asks again in a week, with the next change (no notification); never: this connection is not asked again until the setting Staging first or the role of the site changes. Without staging_reminder, week.', $again );
		self::assertStringNotContainsString( 'New in', Gate::hint( true, true ), 'Asked before: nothing is new any more.' );
	}

	public function testTheSameCallWithTheAcknowledgementRunsWithoutTheGatesArguments(): void {
		$hash = self::connect();
		$this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x' ) );
		$res = $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x', Gate::PARAM => true, Gate::PARAM_REMINDER => 'week' ) );

		self::assertSame( array( 'updated' => true ), $res );
		self::assertSame( 1, $this->runs );
		self::assertSame( array( 'title' => 'x' ), $this->seen, 'The tool never sees the gate\'s arguments.' );
		self::assertSame( 'ok', self::last_log()['status'] );
		self::assertSame( 'title', self::last_log()['keys'], 'Logged without them too.' );
		self::assertEqualsWithDelta( time(), self::entry( $hash )[ Gate::FIELD_ACK ], 5 );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, self::entry( $hash ), 'never is not an answer to the first question.' );

		$this->call( 'wp_update_post', $this->writer(), array( 'title' => 'y' ) );
		self::assertSame( 2, $this->runs, 'Within the week: not asked again, no argument needed.' );
	}

	public function testTheAcknowledgementIsTrueOnlyForTrueAndItsSpellings(): void {
		self::connect();
		foreach ( array( false, 'false', 0, '0', 'no', 'yes please', null, array( true ) ) as $value ) {
			$this->runs = 0;
			AB_MCP_Settings::update_token_fields( AB_MCP_Auth::current_token_hash(), array( Gate::FIELD_ACK => null ) );
			$res = $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x', Gate::PARAM => $value ) );
			self::assertSame( 0, $this->runs, var_export( $value, true ) . ' is no acknowledgement.' );
			self::assertTrue( $res['nothing_changed'] ?? false, var_export( $value, true ) );
		}
		foreach ( array( true, 'true', 1, '1', 'yes', 'on' ) as $value ) {
			$this->runs = 0;
			AB_MCP_Settings::update_token_fields( AB_MCP_Auth::current_token_hash(), array( Gate::FIELD_ACK => null ) );
			self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x', Gate::PARAM => $value ) ), var_export( $value, true ) );
			self::assertSame( 1, $this->runs );
		}
	}

	public function testAnAcknowledgementWithoutAHeldCallBeforeItCountsToo(): void {
		self::connect();
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x', Gate::PARAM => true ) ) );
		self::assertSame( 1, $this->runs, 'The parameter cannot prove the question; the concept says so.' );
	}

	public function testAPreviewCountsAsAWrite(): void {
		self::connect();
		$res = $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x', 'apply' => false ) );
		self::assertTrue( $res['nothing_changed'] ?? false, 'apply false is held like the write: the pause comes before the first look.' );
		self::assertSame( 0, $this->runs );
		self::assertTrue( $this->call( 'wp_search_replace', $this->writer(), array( 'dry_run' => true ) )['nothing_changed'] ?? false );
	}

	public function testAfterSevenDaysTheQuestionComesBackWithTwoMoreAnswers(): void {
		$hash = self::connect();
		$def  = $this->writer();
		$now  = time();
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => $now - 7 * DAY_IN_SECONDS + 60 ) );
		self::assertTrue( Gate::gate( 'wp_update_post', $def, array( 'title' => 'x' ), $now ), 'A minute before the week is over: still acknowledged.' );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array( 'title' => 'x' ) ) );

		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => $now - 7 * DAY_IN_SECONDS ) );
		$held = Gate::gate( 'wp_update_post', $def, array( 'title' => 'x' ), $now );
		self::assertIsArray( $held, 'Exactly seven days: the question comes back.' );
		self::assertTrue( $held['asked_before'] );
		self::assertSame( array( 'week', 'never' ), $held['reminder_options'] );
		self::assertSame( Gate::hint( false, true ), $held['staging_hint'] );
		self::assertNull( $held['new_in_version'] );

		$res = $this->call( 'wp_update_post', $def, array( 'title' => 'x' ) );
		self::assertTrue( $res['nothing_changed'], 'Through run_tool too.' );
		self::assertSame( 'held', self::last_log()['status'] );
		self::assertSame( 1, $this->runs );
	}

	public function testWeekAsksAgainInAWeekAndNeverAsksNoMore(): void {
		$hash = self::connect();
		$def  = $this->writer();
		$now  = time();
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => $now - 8 * DAY_IN_SECONDS ) );

		self::assertTrue( Gate::gate( 'wp_update_post', $def, array( Gate::PARAM => true, Gate::PARAM_REMINDER => 'week' ), $now ) );
		self::assertSame( $now, self::entry( $hash )[ Gate::FIELD_ACK ] );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, self::entry( $hash ) );
		self::assertTrue( Gate::gate( 'wp_update_post', $def, array(), $now + 7 * DAY_IN_SECONDS - 1 ) );
		self::assertIsArray( Gate::gate( 'wp_update_post', $def, array(), $now + 7 * DAY_IN_SECONDS ), 'Another week, then again.' );

		self::assertTrue( Gate::gate( 'wp_update_post', $def, array( Gate::PARAM => true, Gate::PARAM_REMINDER => 'never' ), $now + 7 * DAY_IN_SECONDS ) );
		$never = self::entry( $hash )[ Gate::FIELD_NEVER ];
		self::assertSame( array( 'at' => $now + 7 * DAY_IN_SECONDS, 'setting' => 'ask', 'role' => 'unconfirmed' ), $never );
		self::assertTrue( Gate::gate( 'wp_update_post', $def, array(), $now + 400 * DAY_IN_SECONDS ), 'No more, however long.' );

		Gate::ask_again( array( 'kind' => 'token', 'id' => $hash ) );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, self::entry( $hash ) );
		self::assertArrayNotHasKey( Gate::FIELD_ACK, self::entry( $hash ) );
		$held = Gate::gate( 'wp_update_post', $def, array(), $now + 400 * DAY_IN_SECONDS );
		self::assertIsArray( $held, 'Asked again, as a connection never asked.' );
		self::assertFalse( $held['asked_before'] );
	}

	public function testNeverOnTheFirstAnswerCountsAsWeek(): void {
		$hash = self::connect();
		$now  = time();
		self::assertTrue( Gate::gate( 'wp_update_post', $this->writer(), array( Gate::PARAM => true, Gate::PARAM_REMINDER => 'never' ), $now ) );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, self::entry( $hash ), 'never is offered only once the question came back.' );
		self::assertIsArray( Gate::gate( 'wp_update_post', $this->writer(), array(), $now + 7 * DAY_IN_SECONDS ) );
	}

	/* ------------------------------------------------- before the token */

	public function testTheGateStandsBeforeTheConfirmationToken(): void {
		self::connect();
		$states = 0;
		$def    = $this->writer(
			array(
				'confirm' => array(
					'state'   => static function () use ( &$states ): string {
						++$states;
						return 'plugin 1.0';
					},
					'preview' => static fn(): array => array( 'would' => 'delete' ),
				),
			)
		);
		$held = $this->call( 'wp_delete_plugin', $def, array( 'title' => 'x' ) );
		self::assertTrue( $held['nothing_changed'] );
		self::assertArrayNotHasKey( 'confirm_token', $held, 'Held first: no preview, no token.' );
		self::assertSame( 0, $states, 'The state of the preview was not even asked.' );
		self::assertSame( 'held', self::last_log()['status'] );

		$preview = $this->call( 'wp_delete_plugin', $def, array( 'title' => 'x', Gate::PARAM => true ) );
		self::assertTrue( $preview['confirm_required'], 'Acknowledged: now the preview with its token.' );
		self::assertSame( 0, $this->runs );
		self::assertSame( 'preview', self::last_log()['status'] );

		$res = $this->call( 'wp_delete_plugin', $def, array( 'title' => 'x', 'confirm_token' => $preview['confirm_token'] ) );
		self::assertSame( array( 'updated' => true ), $res, 'The token matches the call without the gate\'s argument: it was taken out before the token was issued.' );
		self::assertSame( 1, $this->runs );
		self::assertSame( array( 'title' => 'x' ), $this->seen );
	}

	/* ------------------------------------------------------------ stamp */

	public function testTheStampIsWrittenOnceAndTellsOldConnectionsFromNew(): void {
		self::assertSame( 0, Gate::since() );
		self::assertTrue( Gate::stamp( 1000 ) );
		self::assertSame( 1000, Gate::since() );
		self::assertFalse( Gate::stamp( 2000 ), 'Once.' );
		self::assertSame( 1000, Gate::since() );

		self::assertTrue( Gate::is_existing( array( 'created' => 999 ) ) );
		self::assertFalse( Gate::is_existing( array( 'created' => 1000 ) ), 'The same second: made under this version.' );
		self::assertFalse( Gate::is_existing( array( 'created' => 1001 ) ) );
		self::assertFalse( Gate::is_existing( array() ), 'No created time: nothing to compare.' );
		update_option( AB_MCP_Settings::OPT_OPTIONS, array( 'site_mode' => 'full' ) );
		self::assertFalse( Gate::is_existing( array( 'created' => 999 ) ), 'Without a stamp, no connection is old.' );

		$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );
		self::assertSame( 2, substr_count( $plugin, 'AB_MCP_Staging_Gate::stamp();' ), 'On every load (the constructor) and on activation.' );
		self::assertLessThan( strpos( $plugin, 'load_bundled_translations();' ), strpos( $plugin, 'AB_MCP_Staging_Gate::stamp();' ), 'Before anything else of the load.' );
	}

	public function testAConnectionFromBeforeThisVersionIsToldWhatIsNew(): void {
		$hash = self::connect();
		Gate::stamp( time() + 100 );
		$res = $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x' ) );
		self::assertSame( '4.15.0', $res['new_in_version'] );
		self::assertSame( '4.15.0', Gate::SINCE_VERSION, 'The version that brought the gate, not the running one.' );
		self::assertSame( Gate::hint( true, false ), $res['staging_hint'] );
		self::assertStringStartsWith( 'New in AlphaBridge MCP 4.15.0', $res['staging_hint'] );

		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => time() - 8 * DAY_IN_SECONDS ) );
		$again = $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x' ) );
		self::assertNull( $again['new_in_version'], 'Asked before: not new any more.' );

		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => null, 'created' => time() + 200 ) );
		self::assertNull( $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x' ) )['new_in_version'], 'Made after the stamp: not new.' );
	}

	/* -------------------------------------------------------------- site */

	public function testTheConfirmedStagingSiteAndAPairStandOutsideTheGate(): void {
		self::connect();
		$def = $this->writer();

		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_STAGING, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array() ), 'Role staging, confirmed on the settings page.' );

		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_UNCONFIRMED, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_STAGING, 'https://www.example.org', AB_MCP_Staging::ROLE_PRODUCTION, 7, AB_MCP_Staging::SOURCE_HUB );
		self::assertSame( 'hub', AB_MCP_Staging::record()['role']['source'] );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array() ), 'Role staging from the hub: with ask, every source counts.' );

		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_UNCONFIRMED, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_PRODUCTION, self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array() ), 'Production with a pair: the staging site is there.' );

		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_PRODUCTION, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertTrue( $this->call( 'wp_update_post', $def, array() )['nothing_changed'] ?? false, 'Production without a pair: held.' );
		self::assertSame( 'production', AB_MCP_Staging::role() );
	}

	public function testWithRequireOnlyWhatAPersonConfirmedOnTheSettingsPageCounts(): void {
		self::connect();
		Gate::set_setting( Gate::REQUIRE );
		$def = $this->writer();

		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_STAGING, 'https://www.example.org', AB_MCP_Staging::ROLE_PRODUCTION, 7, AB_MCP_Staging::SOURCE_HUB );
		$res = $this->call( 'wp_update_post', $def, array() );
		self::assertInstanceOf( WP_Error::class, $res, 'Role and pair from the hub: a token holder wrote them, so they do not lift the person\'s release.' );
		self::assertSame( 'ab_mcp_staging_required', $res->get_error_code() );
		self::assertTrue( Gate::site_exempt( Gate::ASK ), 'With ask they would count.' );
		self::assertFalse( Gate::site_exempt( Gate::REQUIRE ) );

		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_UNCONFIRMED, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_STAGING, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array() ), 'Role staging from the settings page.' );

		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_UNCONFIRMED, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_PRODUCTION, self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array() ), 'A pair from the settings page.' );

		// The role from the settings page, the pair written by the hub over it: the pair's source is the hub.
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_PRODUCTION, 'https://www.example.org', AB_MCP_Staging::ROLE_STAGING, 7, AB_MCP_Staging::SOURCE_HUB );
		self::assertSame( 'form', AB_MCP_Staging::record()['role']['source'] );
		self::assertSame( 'hub', AB_MCP_Staging::record()['pair']['source'] );
		self::assertInstanceOf( WP_Error::class, $this->call( 'wp_update_post', $def, array() ), 'Role production (not exempt) and a pair from the hub: refused.' );
	}

	public function testOffHoldsNothing(): void {
		self::connect();
		Gate::set_setting( Gate::OFF );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x' ) ) );
		self::assertSame( 1, $this->runs );
		self::assertSame( 'ok', self::last_log()['status'] );
	}

	/* ----------------------------------------------------------- require */

	public function testRequireRefusesWithTheWayUntilAPersonReleasesTheConnection(): void {
		$hash = self::connect( 'ChatGPT' );
		Gate::set_setting( Gate::REQUIRE );
		$def = $this->writer();

		$res = $this->call( 'wp_update_post', $def, array( 'title' => 'x', Gate::PARAM => true ) );
		self::assertInstanceOf( WP_Error::class, $res, 'An acknowledgement is no release.' );
		self::assertSame( 'ab_mcp_staging_required', $res->get_error_code() );
		self::assertSame( 'tool', $res->get_error_data()[ AB_MCP_REST_Controller::RUN_KIND ] );
		$msg = $res->get_error_message();
		self::assertStringStartsWith( 'Nothing ran: this site requires staging first. It counts as the production site, where a change is visible to visitors at once, and a change here needs a release by a person on the settings page.', $msg );
		self::assertStringContainsString( '1. Work on a staging site instead: wp_staging_status shows where the site stands and the next step, and the prompt staging_workflow has the steps.', $msg );
		self::assertStringContainsString( '2. To change this site anyway, an administrator releases this connection on the settings page of AlphaBridge MCP under Staging first («Work without staging»), for 24 hours or until revoked. 3. Or an administrator sets Staging first to Ask or Off there.', $msg );
		self::assertStringContainsString( 'Direct link: ' . AB_MCP_Guidance::settings_url( 'ab-staging-first' ), $msg );
		self::assertStringEndsWith( AB_MCP_Guidance::HANDOVER, $msg );
		self::assertSame( 0, $this->runs );
		self::assertSame( 'denied', self::last_log()['status'] );
		self::assertStringContainsString( 'requires staging first', self::last_log()['message'] );

		$until = Gate::release( array( 'kind' => 'token', 'id' => $hash ), 7 );
		self::assertEqualsWithDelta( time() + 24 * HOUR_IN_SECONDS, $until, 5 );
		self::assertSame( $until, self::entry( $hash )[ Gate::FIELD_RELEASE ] );
		self::assertSame( 7, self::entry( $hash )[ Gate::FIELD_RELEASE_BY ]['user_id'] );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array( 'title' => 'x' ) ), 'Released: runs, no acknowledgement needed.' );
		self::assertSame( 1, $this->runs );

		$history = Gate::history();
		self::assertCount( 1, $history );
		self::assertSame( 'release', $history[0]['action'] );
		self::assertSame( array( 'token', $hash ), array( $history[0]['kind'], $history[0]['id'] ) );
		self::assertSame( 'ChatGPT', $history[0]['label'] );
		self::assertSame( $until, $history[0]['until'] );
		self::assertSame( 7, $history[0]['user_id'] );
	}

	public function testAReleaseEndsAfterTwentyFourHoursOrWhenRevoked(): void {
		$hash = self::connect();
		Gate::set_setting( Gate::REQUIRE );
		$def = $this->writer();
		$now = time();

		$conn = array( 'kind' => 'token', 'id' => $hash );
		Gate::release( $conn, 7, $now );
		self::assertTrue( Gate::gate( 'wp_update_post', $def, array(), $now + 24 * HOUR_IN_SECONDS - 1 ) );
		self::assertInstanceOf( WP_Error::class, Gate::gate( 'wp_update_post', $def, array(), $now + 24 * HOUR_IN_SECONDS ), 'Exactly 24 hours: over.' );

		Gate::release( $conn, 7, $now );
		self::assertTrue( Gate::revoke( $conn, 9, $now + 60 ) );
		self::assertArrayNotHasKey( Gate::FIELD_RELEASE, self::entry( $hash ) );
		self::assertArrayNotHasKey( Gate::FIELD_RELEASE_BY, self::entry( $hash ) );
		self::assertInstanceOf( WP_Error::class, $this->call( 'wp_update_post', $def, array() ) );
		self::assertSame( 0, $this->runs );
		self::assertSame( array( 'release', 'release', 'revoke' ), array_column( Gate::history(), 'action' ) );
		self::assertSame( 9, Gate::history()[2]['user_id'] );
		self::assertFalse( Gate::revoke( $conn, 9, $now + 120 ), 'Nothing running: nothing to revoke, nothing in the history.' );
		self::assertCount( 3, Gate::history() );
		self::assertFalse( Gate::release( array( 'kind' => 'token', 'id' => 'no-such-hash' ), 7 ) );
		self::assertFalse( Gate::revoke( array( 'kind' => 'token', 'id' => 'no-such-hash' ), 7 ) );
		self::assertFalse( Gate::release( array( 'kind' => 'user', 'id' => '42' ), 7 ), 'An account the gate never saw.' );
	}

	/* ---------------------------------------------------------- no token */

	public function testACallWithoutATokenIsKeptByItsAccountAndNotExempt(): void {
		self::assertSame( '', AB_MCP_Auth::current_token_hash() );
		self::assertSame( array( 'kind' => 'user', 'id' => '7' ), Gate::connection() );
		$def = $this->writer();

		$res = $this->call( 'wp_update_post', $def, array( 'title' => 'x' ), 'ability' );
		self::assertTrue( $res['nothing_changed'] );
		self::assertNull( $res['new_in_version'], 'No token entry: nothing to date.' );
		self::assertSame( 'held', self::last_log()['status'] );
		self::assertSame( 'ability', self::last_log()['via'] );
		self::assertSame( array(), AB_MCP_Settings::get_tokens(), 'Nothing is written into the token store.' );

		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array( 'title' => 'x', Gate::PARAM => true ), 'ability' ) );
		self::assertEqualsWithDelta( time(), Gate::user_states()[7][ Gate::FIELD_ACK ], 5 );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array(), 'ability' ), 'Acknowledged for this account.' );

		$GLOBALS['ab_test_current_user'] = 9;
		self::assertTrue( $this->call( 'wp_update_post', $def, array(), 'ability' )['nothing_changed'] ?? false, 'Another account: asked.' );

		Gate::set_setting( Gate::REQUIRE );
		$GLOBALS['ab_test_current_user'] = 7;
		$res = $this->call( 'wp_update_post', $def, array(), 'ability' );
		self::assertInstanceOf( WP_Error::class, $res );
		self::assertStringContainsString( '2. This call came through the WordPress abilities adapter: to change this site anyway, an administrator releases its account on the settings page of AlphaBridge MCP under Staging first («Work without staging»), for 24 hours or until revoked. 3. Or an administrator sets Staging first to Ask or Off there.', $res->get_error_message() );
		self::assertStringNotContainsString( 'releases this connection', $res->get_error_message() );
		self::assertEqualsWithDelta( time(), Gate::user_states()[7][ Gate::FIELD_SEEN ], 5, 'The refused account is noted, so the settings page lists it.' );

		$conn  = array( 'kind' => 'user', 'id' => '7' );
		$until = Gate::release( $conn, 9 );
		self::assertEqualsWithDelta( time() + 24 * HOUR_IN_SECONDS, $until, 5 );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array(), 'ability' ), 'Released by account: runs.' );
		self::assertSame( array( 'release', 'user', '7', 'user7', 9 ), array( Gate::history()[0]['action'], Gate::history()[0]['kind'], Gate::history()[0]['id'], Gate::history()[0]['label'], Gate::history()[0]['user_id'] ) );
		self::assertTrue( Gate::revoke( $conn, 9 ) );
		self::assertInstanceOf( WP_Error::class, $this->call( 'wp_update_post', $def, array(), 'ability' ), 'Revoked: refused again.' );
		self::assertSame( 'revoke', Gate::history()[1]['action'] );
		$GLOBALS['ab_test_current_user'] = 9;
		self::assertInstanceOf( WP_Error::class, $this->call( 'wp_update_post', $def, array(), 'ability' ), 'Another account is not released.' );
		self::assertArrayHasKey( 9, Gate::user_states(), 'And noted as seen.' );
	}

	public function testAHeldCallOfAnAccountIsNotedSoTheSettingsPageListsIt(): void {
		$def = $this->writer();
		self::assertSame( array(), Gate::user_states() );
		$this->call( 'wp_update_post', $def, array( 'title' => 'x' ), 'ability' );
		self::assertSame( array( Gate::FIELD_SEEN ), array_keys( Gate::user_states()[7] ) );
		self::assertTrue( Gate::known( array( 'kind' => 'user', 'id' => '7' ) ) );
		self::assertFalse( Gate::known( array( 'kind' => 'user', 'id' => '9' ) ) );
		self::assertStringContainsString( '<td>WordPress abilities adapter, account user7</td><td>Asks with the first change.</td>', $this->admin( 'preview_card_html' ) );
	}

	/* ------------------------------------------------- the instructions */

	/** The server instructions for a registry with the staging tools and one writing tool, as initialize hands them out. */
	private function instructions(): string {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		$r->register( 'wp_update_post', array( 'capability' => 'edit_posts', 'readonly' => false ) );
		return ( new ReflectionMethod( AB_MCP_REST_Controller::class, 'instructions' ) )->invoke( new AB_MCP_REST_Controller( $r ) );
	}

	const ASK_SENTENCE     = 'Before the first change on this site, call wp_staging_status.';
	const REQUIRE_SENTENCE = 'This site requires staging first: a writing call is refused until a person releases this connection on the settings page of AlphaBridge MCP under Staging first (the refusal has the steps and the link). Until then, work on a staging site: wp_staging_status shows where the site stands, and the prompt staging_workflow has the steps.';

	public function testWouldHoldNextSaysWhatTheGateDecides(): void {
		$hash = self::connect();
		$conn = array( 'kind' => 'token', 'id' => $hash );
		$now  = time();
		self::assertSame( Gate::ASK, Gate::would_hold_next( $now ) );
		self::assertSame( Gate::ASK, Gate::would_hold_next( $now, $conn, array() ) );
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => $now - 60 ) );
		self::assertSame( Gate::RUNS, Gate::would_hold_next( $now ), 'Acknowledged this week.' );
		self::assertSame( Gate::ASK, Gate::would_hold_next( $now + 7 * DAY_IN_SECONDS ), 'The week is over.' );
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_NEVER => array( 'at' => $now ) ) );
		self::assertSame( Gate::RUNS, Gate::would_hold_next( $now + 400 * DAY_IN_SECONDS ), 'No more.' );
		Gate::ask_again( $conn );
		Gate::set_setting( Gate::OFF );
		self::assertSame( Gate::RUNS, Gate::would_hold_next( $now ) );
		Gate::set_setting( Gate::REQUIRE );
		self::assertSame( Gate::REQUIRE, Gate::would_hold_next( $now ) );
		Gate::release( $conn, 7, $now );
		self::assertSame( Gate::RUNS, Gate::would_hold_next( $now + 23 * HOUR_IN_SECONDS ) );
		self::assertSame( Gate::REQUIRE, Gate::would_hold_next( $now + 24 * HOUR_IN_SECONDS ) );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_STAGING, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( Gate::RUNS, Gate::would_hold_next( $now + 48 * HOUR_IN_SECONDS ), 'The staging site.' );
		Gate::set_setting( Gate::ASK );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_PRODUCTION, self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( Gate::RUNS, Gate::would_hold_next( $now ), 'Paired.' );
		self::assertSame( Gate::RUNS, Gate::would_hold_next( $now, array( 'kind' => 'user', 'id' => '7' ), array() ), 'For every connection: the site is outside the gate.' );
	}

	public function testTheInstructionsSayOnlyWhatTheGateWouldDoNext(): void {
		$hash = self::connect();
		$conn = array( 'kind' => 'token', 'id' => $hash );
		$text = $this->instructions();
		self::assertStringContainsString( self::ASK_SENTENCE, $text, 'Ask, not acknowledged: the question.' );
		self::assertStringContainsString( 'If the person declines, go ahead here with staging_acknowledged true.', $text, 'With the way to go ahead.' );
		self::assertStringNotContainsString( self::REQUIRE_SENTENCE, $text );

		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => time() ) );
		self::assertStringNotContainsStringIgnoringCase( 'staging', $this->instructions(), 'Acknowledged this week: not a word, the gate holds nothing.' );
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => time() - 8 * DAY_IN_SECONDS ) );
		self::assertStringContainsString( self::ASK_SENTENCE, $this->instructions(), 'The week is over: the question again.' );
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_NEVER => array( 'at' => time() ) ) );
		self::assertStringNotContainsStringIgnoringCase( 'staging', $this->instructions(), 'No more.' );
		Gate::ask_again( $conn );

		Gate::set_setting( Gate::OFF );
		self::assertStringNotContainsStringIgnoringCase( 'staging', $this->instructions(), 'Off.' );

		Gate::set_setting( Gate::REQUIRE );
		$text = $this->instructions();
		self::assertStringContainsString( self::REQUIRE_SENTENCE, $text );
		self::assertStringNotContainsString( self::ASK_SENTENCE, $text );
		self::assertLessThan( strpos( $text, ' NOT ON THIS SITE' ), strpos( $text, self::REQUIRE_SENTENCE ), 'Where the ask sentence stands.' );
		Gate::release( $conn, 7 );
		self::assertStringNotContainsStringIgnoringCase( 'staging', $this->instructions(), 'Released: not a word.' );

		Gate::set_setting( Gate::ASK );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_STAGING, '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertStringNotContainsStringIgnoringCase( 'staging', $this->instructions(), 'The staging site itself.' );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_PRODUCTION, self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertStringNotContainsStringIgnoringCase( 'staging', $this->instructions(), 'Paired.' );
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_UNCONFIRMED, '', null, 7, AB_MCP_Staging::SOURCE_FORM );

		self::scope( 'read' );
		self::assertStringNotContainsStringIgnoringCase( 'staging', $this->instructions(), 'offered() stays the precondition: a connection that only reads.' );
	}

	public function testSavingTheFormConfirmsWhatTheHubWroteAndLiftsRequire(): void {
		self::connect();
		Gate::set_setting( Gate::REQUIRE );
		$def = $this->writer();
		AB_MCP_Staging::save( AB_MCP_Staging::ROLE_STAGING, 'https://www.example.org', AB_MCP_Staging::ROLE_PRODUCTION, 9, AB_MCP_Staging::SOURCE_HUB );
		self::assertInstanceOf( WP_Error::class, $this->call( 'wp_update_post', $def, array() ), 'From the hub: refused.' );

		$saved = AB_MCP_Staging::save( AB_MCP_Staging::ROLE_STAGING, 'https://www.example.org', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'role_changed' => false, 'pair_changed' => false ), $saved, 'The same values.' );
		self::assertSame( array( 'form', 7 ), array( AB_MCP_Staging::record()['role']['source'], AB_MCP_Staging::record()['role']['user_id'] ) );
		self::assertSame( array( 'form', 7 ), array( AB_MCP_Staging::record()['pair']['source'], AB_MCP_Staging::record()['pair']['user_id'] ) );
		self::assertSame( array( 'updated' => true ), $this->call( 'wp_update_post', $def, array() ), 'Confirmed on the settings page: the site stands outside the gate.' );
	}

	/* ------------------------------------------------------------ drafts */

	public function testTheWayWithoutStagingIsWrittenInOnePlace(): void {
		$files = array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/includes/*/*.php' ) );
		$where = array();
		foreach ( $files as $file ) {
			foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $i => $line ) {
				if ( preg_match( '/posts and pages alone|take effect at once/', $line ) && ! preg_match( '/^\s*(\*|\/\/)/', $line ) ) {
					$where[] = basename( $file ) . ':' . ( $i + 1 );
				}
			}
		}
		self::assertCount( 3, $where, 'Three literals, all in AB_MCP_Staging: ' . implode( ', ', $where ) );
		foreach ( $where as $place ) {
			self::assertStringStartsWith( 'class-staging.php:', $place );
		}
		self::assertSame( 'drafts for posts and pages alone', AB_MCP_Staging::drafts_in_instructions() );
		self::assertStringContainsString( 'posts and pages alone', AB_MCP_Staging::drafts_in_prompt() );
		self::assertStringContainsString( 'posts and pages alone', AB_MCP_Staging::drafts_fallback() );
	}

	public function testTheInstructionsThePromptTheChecklistAndTheHintTakeItFromThere(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		$r->register( 'wp_update_post', array( 'capability' => 'edit_posts', 'readonly' => false ) );
		$instructions = ( new ReflectionMethod( AB_MCP_REST_Controller::class, 'instructions' ) )->invoke( new AB_MCP_REST_Controller( $r ) );
		self::assertStringContainsString( ', or ' . AB_MCP_Staging::drafts_in_instructions() . '; its checklist has the steps', $instructions );
		$prompt = null;
		foreach ( apply_filters( 'ab_mcp_prompts', array(), $r ) as $p ) {
			if ( 'staging_workflow' === $p['name'] ) {
				$prompt = $p;
			}
		}
		self::assertStringContainsString( ' ' . AB_MCP_Staging::drafts_in_prompt() . ' Follow the steps', $prompt['text'] );
		$items = AB_MCP_Staging::checklist( AB_MCP_Staging::ROLE_UNCONFIRMED, null, null, false, AB_MCP_Staging::host() );
		self::assertSame( AB_MCP_Staging::drafts_fallback(), $items[1]['fallback'] );
		self::assertStringContainsString( ', or ' . AB_MCP_Staging::drafts_in_instructions() . '.', Gate::hint( false, false ) );
		self::assertStringContainsString( ', or ' . AB_MCP_Staging::drafts_in_instructions() . '.', Gate::hint( false, true ) );
	}

	/* --------------------------------------------------- token fields */

	public function testTheTokenHashIsThatOfTheAuthenticatingTokenAndResetOnEveryCheck(): void {
		$hash = self::connect();
		self::assertSame( $hash, AB_MCP_Auth::current_token_hash() );
		self::assertFalse( AB_MCP_Auth::verify_token( 'abmcp_not_a_token_at_all_but_long_enough' ) );
		self::assertSame( '', AB_MCP_Auth::current_token_hash(), 'A check that fails leaves no hash behind, as it leaves no scope.' );
		self::assertSame( 'full', AB_MCP_Auth::current_scope() );
		self::assertSame( array( 'kind' => 'user', 'id' => '7' ), Gate::connection(), 'Without a token, the account.' );
	}

	public function testTokenFieldsAreWrittenByHashAndNullRemoves(): void {
		$hash = self::connect();
		self::assertTrue( AB_MCP_Settings::update_token_fields( $hash, array( 'staging_ack' => 5, 'hash' => 'x', 'user_id' => 99 ) ) );
		self::assertSame( 5, self::entry( $hash )['staging_ack'] );
		self::assertSame( 7, self::entry( $hash )['user_id'], 'The fields that identify the entry are not written.' );
		self::assertTrue( AB_MCP_Settings::update_token_fields( $hash, array( 'staging_ack' => null ) ) );
		self::assertArrayNotHasKey( 'staging_ack', self::entry( $hash ) );
		self::assertFalse( AB_MCP_Settings::update_token_fields( 'unknown', array( 'staging_ack' => 5 ) ) );
	}

	/* ---------------------------------------------------- settings page */

	public function testTheSectionShowsTheSettingAndEveryConnectionWithItsState(): void {
		$hash = self::connect( 'Claude' );
		$html = $this->admin( 'preview_card_html' );
		self::assertStringContainsString( '<div class="ab-staging ab-staging-first" id="ab-staging-first">', $html );
		self::assertStringContainsString( 'Staging first</h3>', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="nonce-ab_mcp_staging_first"', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="ab_mcp_staging_first">', $html );
		self::assertStringContainsString( 'value="off"> Off: nothing is asked.', $html );
		self::assertStringContainsString( 'value="ask" checked=\'checked\'> Ask: the first change a connection makes here is paused once', $html );
		self::assertStringContainsString( 'value="require"> Require: every change here is refused until you release the connection below', $html );
		self::assertStringContainsString( '<td>Claude</td><td>Asks with the first change.</td><td class="ab-actions"></td>', $html );
		self::assertLessThan( strpos( $html, 'id="ab-staging-first"' ), strpos( $html, 'id="ab-staging"' ), 'Below the role of the site, in the same card.' );
		self::assertDoesNotMatchRegularExpression( '/\bPro\b|Agency/', $html );

		$now = time();
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => $now ) );
		Gate::save_state( array( 'kind' => 'user', 'id' => '9' ), array( Gate::FIELD_ACK => $now - 10 * DAY_IN_SECONDS, Gate::FIELD_NEVER => array( 'at' => $now - 1000 ) ) );
		$html = $this->admin( 'preview_card_html' );
		self::assertStringContainsString( '<td>Claude</td><td>Went ahead here on ', $html );
		self::assertStringContainsString( '; asks again from ', $html );
		self::assertStringContainsString( '<td>WordPress abilities adapter, account user9</td><td>Asks no more, since ', $html );
		self::assertStringContainsString( '<input type="hidden" name="do" value="ask_again"><input type="hidden" name="account" value="9"><button class="ab-btn ab-btn--ghost ab-btn--sm">Ask again</button>', $html );
		self::assertStringNotContainsString( 'name="hash"', $html, 'The acknowledged connection has no button.' );

		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_ACK => $now - 8 * DAY_IN_SECONDS ) );
		self::assertStringContainsString( '<td>Claude</td><td>Asks again with the next change.</td>', $this->admin( 'preview_card_html' ) );

		Gate::set_setting( Gate::REQUIRE );
		$html = $this->admin( 'preview_card_html' );
		self::assertStringContainsString( '<td>Claude</td><td>Not released: every change is refused.</td><td class="ab-actions"><form', $html );
		self::assertStringContainsString( '<input type="hidden" name="do" value="release"><input type="hidden" name="hash" value="' . $hash . '"><button class="ab-btn ab-btn--ghost ab-btn--sm">Work without staging (24 hours)</button>', $html );
		self::assertStringContainsString( 'account user9</td><td>Not released: every change is refused.</td><td class="ab-actions"><form', $html, 'The account of the abilities adapter: the same state and button.' );
		self::assertStringContainsString( '<input type="hidden" name="do" value="release"><input type="hidden" name="account" value="9"><button class="ab-btn ab-btn--ghost ab-btn--sm">Work without staging (24 hours)</button>', $html );

		Gate::release( array( 'kind' => 'token', 'id' => $hash ), 7 );
		Gate::release( array( 'kind' => 'user', 'id' => '9' ), 7 );
		$html = $this->admin( 'preview_card_html' );
		self::assertStringContainsString( '<td>Claude</td><td>Released to work without staging until ', $html );
		self::assertStringContainsString( ', by user7.</td>', $html );
		self::assertStringContainsString( '<input type="hidden" name="do" value="revoke"><input type="hidden" name="hash" value="' . $hash . '"><button class="ab-btn ab-btn--ghost ab-btn--sm">Revoke</button>', $html );
		self::assertStringContainsString( 'account user9</td><td>Released to work without staging until ', $html );
		self::assertStringContainsString( '<input type="hidden" name="do" value="revoke"><input type="hidden" name="account" value="9"><button class="ab-btn ab-btn--ghost ab-btn--sm">Revoke</button>', $html );

		Gate::set_setting( Gate::OFF );
		self::assertStringNotContainsString( 'ab-staging-first__table', $this->admin( 'preview_card_html' ), 'Off: no table.' );
	}

	public function testTheSettingFormSavesBehindItsNonceAndTheCapability(): void {
		$exit = $this->submit( 'handle_staging_first', array( 'staging_first' => 'require' ), 'nonce-ab_mcp_staging_first' );
		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=staging_first_saved', $exit->detail, 'Changed, nothing to take back: the plain notice.' );
		self::assertStringEndsWith( '#ab-staging-first', $exit->detail );
		self::assertSame( Gate::REQUIRE, Gate::setting() );

		$exit = $this->submit( 'handle_staging_first', array( 'staging_first' => 'require' ), 'nonce-ab_mcp_staging_first' );
		self::assertStringContainsString( 'ab_notice=staging_first_saved', $exit->detail, 'Unchanged: the plain notice, not «asked again».' );

		$hash = self::connect();
		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_NEVER => array( 'at' => time() ) ) );
		$exit = $this->submit( 'handle_staging_first', array( 'staging_first' => 'ask' ), 'nonce-ab_mcp_staging_first' );
		self::assertStringContainsString( 'ab_notice=staging_first_reset', $exit->detail, 'Changed and one «no more» taken back: says so.' );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, self::entry( $hash ) );
		$exit = $this->submit( 'handle_staging_first', array( 'staging_first' => 'require' ), 'nonce-ab_mcp_staging_first' );
		self::assertStringContainsString( 'ab_notice=staging_first_saved', $exit->detail );

		$exit = $this->submit( 'handle_staging_first', array( 'staging_first' => 'maybe' ), 'nonce-ab_mcp_staging_first' );
		self::assertStringContainsString( 'ab_notice=staging_first_unknown', $exit->detail );
		self::assertSame( Gate::REQUIRE, Gate::setting(), 'Nothing saved.' );

		$exit = $this->submit( 'handle_staging_first', array( 'staging_first' => 'off' ), 'nonce-ab_mcp_staging' );
		self::assertSame( 'die', $exit->kind, 'Another form\'s nonce.' );
		self::assertSame( Gate::REQUIRE, Gate::setting() );

		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$exit                   = $this->submit( 'handle_staging_first', array( 'staging_first' => 'off' ), 'nonce-ab_mcp_staging_first' );
		self::assertSame( 'die', $exit->kind );
		self::assertSame( 'Insufficient permissions.', $exit->detail );
		self::assertSame( Gate::REQUIRE, Gate::setting() );
	}

	public function testTheConnectionFormReleasesRevokesAndAsksAgain(): void {
		$hash = self::connect();
		Gate::set_setting( Gate::REQUIRE );

		$exit = $this->submit( 'handle_staging_connection', array( 'do' => 'release', 'hash' => $hash ), 'nonce-ab_mcp_staging_connection' );
		self::assertStringContainsString( 'ab_notice=staging_released', $exit->detail );
		self::assertTrue( Gate::released( self::entry( $hash ), time() ) );
		self::assertSame( 7, self::entry( $hash )[ Gate::FIELD_RELEASE_BY ]['user_id'] );

		$exit = $this->submit( 'handle_staging_connection', array( 'do' => 'revoke', 'hash' => $hash ), 'nonce-ab_mcp_staging_connection' );
		self::assertStringContainsString( 'ab_notice=staging_revoked', $exit->detail );
		self::assertFalse( Gate::released( self::entry( $hash ), time() ) );

		AB_MCP_Settings::update_token_fields( $hash, array( Gate::FIELD_NEVER => array( 'at' => time() ), Gate::FIELD_ACK => time() ) );
		$exit = $this->submit( 'handle_staging_connection', array( 'do' => 'ask_again', 'hash' => $hash ), 'nonce-ab_mcp_staging_connection' );
		self::assertStringContainsString( 'ab_notice=staging_ask_again', $exit->detail );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, self::entry( $hash ) );
		self::assertArrayNotHasKey( Gate::FIELD_ACK, self::entry( $hash ) );

		Gate::save_state( array( 'kind' => 'user', 'id' => '9' ), array( Gate::FIELD_NEVER => array( 'at' => time() ) ) );
		$exit = $this->submit( 'handle_staging_connection', array( 'do' => 'ask_again', 'account' => '9' ), 'nonce-ab_mcp_staging_connection' );
		self::assertStringContainsString( 'ab_notice=staging_ask_again', $exit->detail );
		self::assertArrayNotHasKey( Gate::FIELD_NEVER, Gate::user_states()[9] );

		$exit = $this->submit( 'handle_staging_connection', array( 'do' => 'release', 'account' => '9' ), 'nonce-ab_mcp_staging_connection' );
		self::assertStringContainsString( 'ab_notice=staging_released', $exit->detail, 'The account of the abilities adapter is released the same way.' );
		self::assertTrue( Gate::released( Gate::user_states()[9], time() ) );
		self::assertSame( 7, Gate::user_states()[9][ Gate::FIELD_RELEASE_BY ]['user_id'] );
		$exit = $this->submit( 'handle_staging_connection', array( 'do' => 'revoke', 'account' => '9' ), 'nonce-ab_mcp_staging_connection' );
		self::assertStringContainsString( 'ab_notice=staging_revoked', $exit->detail );
		self::assertFalse( Gate::released( Gate::user_states()[9], time() ) );

		foreach ( array(
			array( 'do' => 'release', 'hash' => 'unknown' ),
			array( 'do' => 'revoke', 'hash' => 'unknown' ),
			array( 'do' => 'ask_again', 'account' => '42' ),
			array( 'do' => 'ask_again' ),
			array( 'do' => 'grant', 'hash' => $hash ),
		) as $post ) {
			$exit = $this->submit( 'handle_staging_connection', $post, 'nonce-ab_mcp_staging_connection' );
			self::assertStringContainsString( 'ab_notice=staging_connection_unknown', $exit->detail, wp_json_encode( $post ) );
		}
		self::assertFalse( Gate::released( self::entry( $hash ), time() ) );

		$exit = $this->submit( 'handle_staging_connection', array( 'do' => 'release', 'hash' => $hash ), 'nonce-ab_mcp_staging_first' );
		self::assertSame( 'die', $exit->kind );
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$exit                   = $this->submit( 'handle_staging_connection', array( 'do' => 'release', 'hash' => $hash ), 'nonce-ab_mcp_staging_connection' );
		self::assertSame( 'die', $exit->kind );
		self::assertFalse( Gate::released( self::entry( $hash ), time() ) );
	}

	public function testTheNoticesTheHooksAndTheLogWord(): void {
		foreach ( array(
			'staging_first_saved'        => 'Saved: Staging first.',
			'staging_first_reset'        => 'Saved: Staging first. Every connection that asked no more is asked again.',
			'staging_first_unknown'      => 'Nothing was saved: the setting must be off, ask or require.',
			'staging_released'           => 'Released: this connection may change the site without staging for 24 hours.',
			'staging_revoked'            => 'Revoked: this connection is refused again until you release it.',
			'staging_ask_again'          => 'Noted: this connection is asked again with its next change.',
			'staging_connection_unknown' => 'Nothing was changed: that connection is not known any more',
		) as $key => $text ) {
			$_GET['ab_notice'] = $key;
			self::assertStringContainsString( $text, $this->admin( 'notice' ), $key );
		}
		$admin = new AB_MCP_Admin();
		self::assertSame( 10, has_action( 'admin_post_ab_mcp_staging_first', array( $admin, 'handle_staging_first' ) ) );
		self::assertSame( 10, has_action( 'admin_post_ab_mcp_staging_connection', array( $admin, 'handle_staging_connection' ) ) );

		self::connect();
		$this->call( 'wp_update_post', $this->writer(), array( 'title' => 'x' ) );
		$html = $this->admin( 'render_audit' );
		self::assertStringContainsString( '<span class="ab-dot ab-dot--warn" aria-hidden="true"></span> held</td>', $html, 'held, with the dot of a warning, as denied has.' );
	}

	public function testUninstallRemovesTheGatesOption(): void {
		self::assertStringContainsString( "'" . Gate::OPTION . "',", (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ) );
	}
}
