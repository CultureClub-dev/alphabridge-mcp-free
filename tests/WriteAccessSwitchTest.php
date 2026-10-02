<?php
/**
 * Switching write access on switches everything on.
 *
 * Whoever switches on «Write access for AI assistants» wants the assistants
 * to do what they ask, so every tool is on afterwards — also one an
 * administrator switched off before, and one the rule for switches saved
 * before the modes kept off — and an add-on switches its own items on
 * through ab_mcp_reset_switches. Single tools can be switched off under
 * Fine-tuning afterwards; that holds until write access is switched on the
 * next time. Switching off keeps the switches, and an update alone resets
 * nothing.
 *
 * The notice is version 2; a confirmation of version 1 (4.4.0) stays valid,
 * and a form that still shows version 1 is refused.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AbTestExit;
use AB_MCP_Admin;
use AB_MCP_Settings;
use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;

final class WriteAccessSwitchTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 3 );
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/** Three tools: an ordinary reader, a writer, a Mighty reader. */
	private static function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts.' ) );
		$r->register( 'wp_update_post', array( 'description' => 'Update a post.' ) );
		$r->set_current_group( 'meta-auth', 'Meta' );
		$r->register(
			'wp_get_user_meta',
			array(
				'description' => 'Read profile fields.',
				'dangerous'   => true,
			)
		);
		return $r;
	}

	/** Post to an admin-post handler as user 3, an administrator. */
	private function post( string $handler, array $post, ?AB_MCP_Tool_Registry $r = null ): AbTestExit {
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                           = $post;
		$_REQUEST                        = $post;
		try {
			( new AB_MCP_Admin( $r ?? self::registry() ) )->$handler();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( $handler . ' ended without a redirect or a refusal.' );
	}

	private static function notice_of( AbTestExit $exit ): string {
		self::assertSame( 'redirect', $exit->kind, $exit->detail );
		parse_str( (string) parse_url( $exit->detail, PHP_URL_QUERY ), $q );
		return (string) ( $q['ab_notice'] ?? '' );
	}

	private static function on_post(): array {
		return array(
			'_wpnonce'       => 'nonce-ab_mcp_site_mode',
			'mode'           => 'full',
			'notice_version' => AB_MCP_Site_Mode::NOTICE_VERSION,
			'notice_hash'    => AB_MCP_Site_Mode::notice_hash(),
			'confirm_full'   => '1',
		);
	}

	/** @return array<string,bool> Tool => switched on, as the site reads it. */
	private static function switches( AB_MCP_Tool_Registry $r ): array {
		$out = array();
		foreach ( $r->all() as $name => $def ) {
			$out[ $name ] = AB_MCP_Settings::is_tool_enabled( $name, $def );
		}
		return $out;
	}

	/** A site updated from before the modes, with switches saved then. */
	private static function updated_site_with_switches_off(): void {
		update_option(
			'ab_mcp_options',
			array(
				'rate_limit_per_min' => 120,
				'read_only'          => false,
			)
		);
		update_option(
			'ab_mcp_tool_state',
			array(
				'wp_list_posts'    => false,
				'wp_update_post'   => false,
				'wp_get_user_meta' => false,
			)
		);
		AB_MCP_Settings::maybe_upgrade();
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	private function card(): DOMXPath {
		$m = new ReflectionMethod( AB_MCP_Admin::class, 'mode_card_html' );
		return $this->xpath( (string) $m->invoke( new AB_MCP_Admin( self::registry() ) ) );
	}

	/* ---------------------------------------------- on means everything on */

	public function testSwitchingOnOnThePageSwitchesEveryToolOn(): void {
		self::updated_site_with_switches_off();
		$r = self::registry();
		self::assertTrue( AB_MCP_Settings::get( AB_MCP_Settings::KEY_LEGACY_SWITCHES, false ) );
		self::assertSame( array( 'wp_list_posts' => false, 'wp_update_post' => false, 'wp_get_user_meta' => false ), self::switches( $r ), 'Before: what was switched off stays off after the update.' );

		self::assertSame( 'mode_full', self::notice_of( $this->post( 'handle_site_mode', self::on_post() ) ) );

		self::assertSame( array( 'wp_list_posts' => true, 'wp_update_post' => true, 'wp_get_user_meta' => true ), self::switches( $r ), 'Everything on, the Mighty reader kept off by the rule of before included.' );
		self::assertSame( array(), get_option( 'ab_mcp_tool_state' ), 'No switch stored.' );
		self::assertFalse( AB_MCP_Settings::get( AB_MCP_Settings::KEY_LEGACY_SWITCHES, false ), 'The rule of before no longer applies.' );
	}

	public function testSwitchingOnByCodeSwitchesEveryToolOnToo(): void {
		AB_MCP_Settings::install_defaults();
		AB_MCP_Settings::set_tool_state( array( 'wp_update_post' => false ) );

		AB_MCP_Site_Mode::switch_to_full( 3 );

		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_update_post', array() ) );
		self::assertSame( array(), get_option( 'ab_mcp_tool_state' ) );
	}

	public function testAMightyReaderMarkedOnlySinceTheModesIsOnAfterwardsLikeEveryOther(): void {
		self::updated_site_with_switches_off();
		$since = array( 'dangerous' => true, 'mighty_since' => '4.6.0' );
		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_get_user_meta', $since ), 'Switched off and saved before.' );

		AB_MCP_Site_Mode::switch_to_full( 3 );

		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_get_user_meta', $since ) );
		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_db_query', array( 'dangerous' => true ) ), 'And a Mighty reader without any switch.' );
	}

	public function testAddOnsSwitchTheirItemsOnOnTheCoresAction(): void {
		AB_MCP_Settings::install_defaults();
		AB_MCP_Settings::set_tool_state( array( 'wp_update_post' => false ) );
		$calls = array();
		add_action(
			'ab_mcp_reset_switches',
			static function ( $user, $source ) use ( &$calls ) {
				$calls[] = array( $user, $source, get_option( 'ab_mcp_tool_state' ), AB_MCP_Site_Mode::is_full() );
			},
			10,
			2
		);

		$this->post( 'handle_site_mode', self::on_post() );
		AB_MCP_Site_Mode::switch_to_read( 3 );
		AB_MCP_Site_Mode::switch_to_full( 3 );

		self::assertSame(
			array(
				array( 3, 'settings_form', array(), true ),
				array( 3, 'code', array(), true ),
			),
			$calls,
			'Once per switch-on, with who and how, after the core switched its tools on; never on switching off.'
		);
	}

	public function testAfterSwitchingOnSingleToolsCanBeSwitchedOffUntilTheNextSwitchOn(): void {
		AB_MCP_Settings::install_defaults();
		$r = self::registry();
		$this->post( 'handle_site_mode', self::on_post() );

		$this->post(
			'handle_save',
			array(
				'_wpnonce'      => 'nonce-ab_mcp_save',
				'enabled_tools' => array( 'wp_list_posts', 'wp_get_user_meta' ),
			),
			$r
		);
		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_update_post', array() ), 'Switched off under Fine-tuning: off.' );

		AB_MCP_Site_Mode::switch_to_read( 3 );
		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_update_post', array() ), 'Switching off keeps the switches.' );
		self::assertSame( array( 'wp_list_posts' => true, 'wp_update_post' => false, 'wp_get_user_meta' => true ), get_option( 'ab_mcp_tool_state' ) );

		$this->post( 'handle_site_mode', self::on_post() );
		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_update_post', array() ), 'The next switch-on switches it on again.' );
	}

	public function testSwitchingOnAgainWhileOnChangesNothing(): void {
		AB_MCP_Settings::install_defaults();
		$r = self::registry();
		$this->post( 'handle_site_mode', self::on_post() );
		$this->post(
			'handle_save',
			array(
				'_wpnonce'      => 'nonce-ab_mcp_save',
				'enabled_tools' => array( 'wp_list_posts', 'wp_get_user_meta' ),
			),
			$r
		);
		// Switched on an hour ago, so a new record would show.
		$record         = AB_MCP_Site_Mode::confirmation();
		$record['time'] = time() - 3600;
		AB_MCP_Settings::set( AB_MCP_Site_Mode::KEY_CONFIRMATION, $record );
		$history = count( AB_MCP_Site_Mode::history() );
		$fired   = 0;
		add_action(
			'ab_mcp_reset_switches',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		// The form of an old tab, sent again: valid nonce, notice in force, box ticked.
		self::assertSame( 'mode_already_full', self::notice_of( $this->post( 'handle_site_mode', self::on_post() ) ) );
		// A script that switches on a second time.
		self::assertSame( $record, AB_MCP_Site_Mode::switch_to_full( 3 ), 'Code gets the record in force back.' );

		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_update_post', array() ), 'What was switched off under Fine-tuning stays off.' );
		self::assertSame( array( 'wp_list_posts' => true, 'wp_update_post' => false, 'wp_get_user_meta' => true ), get_option( 'ab_mcp_tool_state' ) );
		self::assertSame( 0, $fired, 'No add-on resets its items either.' );
		self::assertSame( $record, AB_MCP_Site_Mode::confirmation(), '«On since» keeps its date and account.' );
		self::assertSame( $history, count( AB_MCP_Site_Mode::history() ), 'No switch in the history.' );
		self::assertTrue( AB_MCP_Site_Mode::is_full() );
	}

	public function testAnUpdateAloneResetsNothing(): void {
		// A site that went to Full under 4.4.0 and switched a tool off there.
		AB_MCP_Settings::install_defaults();
		AB_MCP_Settings::set( 'site_mode', 'full' );
		AB_MCP_Settings::set_tool_state( array( 'wp_update_post' => false ) );
		$fired = 0;
		add_action(
			'ab_mcp_reset_switches',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		AB_MCP_Settings::maybe_upgrade();
		AB_MCP_Settings::install_defaults();

		self::assertTrue( AB_MCP_Site_Mode::is_full(), 'Still on.' );
		self::assertSame( array( 'wp_update_post' => false ), get_option( 'ab_mcp_tool_state' ), 'The switch stays.' );
		self::assertSame( 0, $fired, 'No add-on resets anything either.' );
	}

	/* ------------------------------------------------------ the notice */

	public function testAConfirmationOfTheFirstNoticeStaysValid(): void {
		// The record 4.4.0 wrote when an administrator confirmed Full.
		AB_MCP_Settings::install_defaults();
		AB_MCP_Settings::set( 'site_mode', 'full' );
		AB_MCP_Settings::set(
			AB_MCP_Site_Mode::KEY_CONFIRMATION,
			array(
				'user_id'        => 3,
				'user_login'     => 'user3',
				'time'           => 1790000000,
				'notice_version' => '1',
				'plugin_version' => '4.4.0',
				'locale'         => 'de_DE',
				'source'         => 'settings_form',
				'notice_hash'    => 'e63a129b792c19f2ffa0625d200070fa821bd84ba172150a7c24264399a3b310',
				'notice_text'    => 'Full lets AI assistants create, change and delete content, files, settings and code on this live site. Switching it on can be destructive and is at your own risk. Make sure you have a current backup.',
				'checkbox_text'  => 'I understand that switching to Full can be destructive and is at my own risk, and I have a current backup.',
			)
		);

		self::assertTrue( AB_MCP_Site_Mode::is_full() );
		self::assertSame( '1', AB_MCP_Site_Mode::confirmation()['notice_version'], 'The record keeps what was confirmed.' );
		self::assertSame( 'Write access on since ' . wp_date( 'Y-m-d H:i', 1790000000 ) . ' · confirmed by user3 · every connection keeps its access level', AB_MCP_Site_Mode::full_since_text() );
		$x = $this->card();
		self::assertSame( 'true', $x->query( '//button[@role="switch"]' )->item( 0 )->getAttribute( 'aria-checked' ) );
		self::assertSame( 0, $x->query( '//input[@name="confirm_full"]' )->length, 'Nobody is asked to confirm again.' );
	}

	public function testTheDateUnderTheSwitchReadsAsInTheAdminsLanguage(): void {
		AB_MCP_Settings::install_defaults();
		$this->post( 'handle_site_mode', self::on_post() );
		$record         = AB_MCP_Site_Mode::confirmation();
		$record['time'] = 1790000000;
		AB_MCP_Settings::set( AB_MCP_Site_Mode::KEY_CONFIRMATION, $record );

		self::assertStringStartsWith( 'Write access on since ' . wp_date( 'Y-m-d H:i', 1790000000 ) . ' · confirmed by user3', AB_MCP_Site_Mode::full_since_text() );
		// German: «Schreibrechte an seit 02.10.2026, 13:22», as in the approved draft.
		$GLOBALS['ab_test_translations'] = array( "date and time format\x04Y-m-d H:i" => 'd.m.Y, H:i' );
		self::assertStringStartsWith( 'Write access on since ' . wp_date( 'd.m.Y, H:i', 1790000000 ) . ' · ', AB_MCP_Site_Mode::full_since_text() );
		self::assertMatchesRegularExpression( '/^\d\d\.\d\d\.\d{4}, \d\d:\d\d$/', wp_date( 'd.m.Y, H:i', 1790000000 ) );
	}

	public function testAFormOfTheFirstNoticeIsRefused(): void {
		// A page loaded before the update shows notice 1; its box agreed to
		// a text that is no longer the one in force.
		$post                   = self::on_post();
		$post['notice_version'] = '1';
		$post['notice_hash']    = 'e63a129b792c19f2ffa0625d200070fa821bd84ba172150a7c24264399a3b310';

		self::assertSame( 'mode_stale', self::notice_of( $this->post( 'handle_site_mode', $post ) ) );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );

		$post['notice_hash'] = AB_MCP_Site_Mode::notice_hash();
		self::assertSame( 'mode_stale', self::notice_of( $this->post( 'handle_site_mode', $post ) ), 'Version 1 with the wording of version 2 is refused too.' );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
	}

	public function testTheFormShowsAndSendsTheNoticeInForce(): void {
		$x = $this->card();

		self::assertSame( '2', $x->query( '//form[@id="ab-mode-form"]//input[@name="notice_version"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( AB_MCP_Site_Mode::notice_hash(), $x->query( '//form[@id="ab-mode-form"]//input[@name="notice_hash"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( AB_MCP_Site_Mode::notice_text(), trim( $x->query( '//*[@id="ab-mode-warning"]' )->item( 0 )->textContent ) );
		self::assertStringContainsString( 'Changes take effect immediately', AB_MCP_Site_Mode::notice_text() );
		self::assertStringContainsString( 'not everything can be undone', AB_MCP_Site_Mode::notice_text() );
		self::assertStringContainsString( 'at your own risk', AB_MCP_Site_Mode::notice_text() );
		self::assertStringContainsString( 'backup', AB_MCP_Site_Mode::checkbox_text() );
	}

	public function testSwitchingOffNeedsNoBoxAndOneClick(): void {
		AB_MCP_Site_Mode::switch_to_full( 3 );
		$x  = $this->card();
		$sw = $x->query( '//button[@role="switch"]' )->item( 0 );

		self::assertSame( 'submit', $sw->getAttribute( 'type' ) );
		self::assertSame( 'read', $x->query( '//form[@id="' . $sw->getAttribute( 'form' ) . '"]//input[@name="mode"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 'mode_read', self::notice_of( $this->post( 'handle_site_mode', array( '_wpnonce' => 'nonce-ab_mcp_site_mode', 'mode' => 'read' ) ) ) );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
	}
}
