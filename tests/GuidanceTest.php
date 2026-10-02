<?php
/**
 * Every refusal leads the person to what they wanted.
 *
 * An assistant that is refused passes it on to the person it works for. So
 * each refusal of the policy gate — write access off, an access level or a
 * role that does not reach, a tool switched off under Fine-tuning — says why
 * in one sentence, what the person can do, gives a direct link to the place
 * where it is done and asks the assistant to pass it on kindly and to try
 * again afterwards (AB_MCP_Guidance::compose()). The same holds where a
 * tool itself refuses because the role of the connection's account does not
 * allow it — this post, this user, publishing (AB_MCP_Guidance::
 * role_refusal()): every such refusal under includes/ goes through it, and
 * only refusals the assistant corrects in its own call stay plain.
 *
 * A tool of AlphaBridge MCP Pro or its Agency plan is not on a free site.
 * Its name is answered with what it is and where it comes from instead of
 * «Unknown tool», from a fixed list of names (the Pro add-on's tests compare
 * it with what Pro registers); the add-on can answer for an inactive licence
 * or a plan without the tool. A site without Pro's tools tells the assistant
 * in its instructions what Pro adds and where. Nothing of Pro is in this
 * plugin and nothing here is locked: no Pro tool is registered or listed.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Audit_Log;
use AB_MCP_Auth;
use AB_MCP_Guidance;
use AB_MCP_REST_Controller;
use AB_MCP_Security;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Content;
use AB_MCP_Tools_Media;
use AB_MCP_Tools_Taxonomy_Comments;
use ReflectionMethod;
use ReflectionProperty;

final class GuidanceTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
	}

	/** Every tool of this plugin, as the plugin registers them. */
	private static function free_registry(): AB_MCP_Tool_Registry {
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

	/** tools/call on this site, as the endpoint answers it. */
	private static function call( AB_MCP_Tool_Registry $r, string $name ): array {
		$m = new ReflectionMethod( AB_MCP_REST_Controller::class, 'tools_call' );
		return $m->invoke( new AB_MCP_REST_Controller( $r ), 7, array( 'name' => $name, 'arguments' => array() ) )['result'];
	}

	private static function assertLeadsTo( string $url, string $message ): void {
		self::assertStringContainsString( ' Direct link: ' . $url . ' ', $message );
		self::assertStringEndsWith( ' Pass this on to the person you are working for in a friendly way, with the steps and the link, and try again once it is done; do not look for a way around it.', $message );
	}

	/* ------------------------------------------------- the policy gate */

	public function testEveryRefusalOfThePolicyGateNamesReasonStepsLinkAndHandover(): void {
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$settings                        = 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp';

		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$role                   = AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) );
		self::assertSame( 'ab_mcp_forbidden', $role->get_error_code() );
		self::assertLeadsTo( 'https://example.test/wp-admin/users.php', $role->get_error_message() );

		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$off                    = AB_MCP_Security::authorize( 'wp_update_post', array() );
		self::assertSame( 'ab_mcp_read_mode', $off->get_error_code() );
		self::assertLeadsTo( $settings . '#ab-mode', $off->get_error_message() );

		update_option( 'ab_mcp_options', array( 'site_mode' => 'full' ) );
		update_option( 'ab_mcp_tool_state', array( 'wp_update_post' => false ) );
		$switched = AB_MCP_Security::authorize( 'wp_update_post', array() );
		self::assertSame( 'ab_mcp_tool_disabled', $switched->get_error_code() );
		self::assertLeadsTo( $settings . '#ab-tool-wp_update_post', $switched->get_error_message() );

		update_option( 'ab_mcp_tool_state', array() );
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'content' );
		$scope = AB_MCP_Security::authorize( 'wp_update_option', array( 'capability' => 'manage_options' ) );
		self::assertSame( 'ab_mcp_scope', $scope->get_error_code() );
		self::assertStringStartsWith( 'This connection has the access level "Content", which does not include the tool "wp_update_option".', $scope->get_error_message() );
		self::assertLeadsTo( $settings . '#ab-connections', $scope->get_error_message() );

		foreach ( array( $role, $off, $switched, $scope ) as $res ) {
			$reason = substr( $res->get_error_message(), 0, (int) strpos( $res->get_error_message(), ' To allow it, ' ) );
			self::assertSame( 1, preg_match_all( '/[.!?](\s|$)/', $reason ), 'The reason in one sentence: ' . $reason );
			self::assertStringContainsString( ' To allow it, ', $res->get_error_message(), 'Then the steps.' );
		}
	}

	public function testTheLinkStaysOnThisSiteAndCarriesOnlyAnAnchor(): void {
		self::assertSame( 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp', AB_MCP_Guidance::settings_url() );
		self::assertSame( 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp#ab-mode', AB_MCP_Guidance::settings_url( 'ab-mode' ) );
		self::assertSame( 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp#ab-tool-wp_xonclick', AB_MCP_Guidance::settings_url( 'ab-tool-wp_x"onclick=' ), 'Only letters, digits, «-» and «_» survive.' );
	}

	/* ----------------------------------------------- the tools of Pro */

	public function testAToolOfProIsNamedWithTheWayToItInsteadOfUnknown(): void {
		$r      = self::free_registry();
		$result = self::call( $r, 'wp_db_query' );
		$text   = $result['content'][0]['text'];

		self::assertTrue( $result['isError'] );
		self::assertStringStartsWith( 'The tool "wp_db_query" is not on this site: it belongs to AlphaBridge MCP Pro, a separate plugin that is not active here.', $text );
		self::assertStringContainsString( 'To use it, the site owner installs and activates AlphaBridge MCP Pro', $text );
		self::assertStringNotContainsString( 'Unknown tool', $text );
		self::assertLeadsTo( 'https://alphabridge-mcp.com/#pricing', $text );
		$last = AB_MCP_Audit_Log::recent( 1 )[0];
		self::assertSame( array( 'wp_db_query', 'denied' ), array( $last['tool'], $last['status'] ), 'Logged like every refusal.' );
	}

	public function testAToolOfTheAgencyPlanSaysSo(): void {
		$text = self::call( self::free_registry(), 'wp_deploy_push_zip' )['content'][0]['text'];

		self::assertStringStartsWith( 'The tool "wp_deploy_push_zip" is not on this site: it belongs to the Agency plan of AlphaBridge MCP Pro', $text );
		self::assertStringContainsString( 'with the Agency plan', $text );
		self::assertLeadsTo( 'https://alphabridge-mcp.com/#pricing', $text );
	}

	public function testAnyOtherNameStaysUnknown(): void {
		$text = self::call( self::free_registry(), 'wp_frobnicate' )['content'][0]['text'];

		self::assertStringStartsWith( 'Unknown tool: wp_frobnicate.', $text );
		self::assertSame( array(), AB_MCP_Audit_Log::recent( 1 ), 'Nothing logged for a name nobody has.' );
		self::assertNull( AB_MCP_Guidance::unavailable_tool( 'wp_frobnicate' ) );
		self::assertNull( AB_MCP_Guidance::unavailable_tool( 'wp_get_post' ), 'A tool of this plugin is no tool of Pro.' );
	}

	public function testTheProAddOnAnswersForAnInactiveLicence(): void {
		$seen = array();
		add_filter(
			'ab_mcp_unavailable_tool_message',
			static function ( $message, $name, $edition ) use ( &$seen ) {
				$seen[] = array( $name, $edition, '' !== $message );
				return 'agency' === $edition ? '' : 'Activate the licence.';
			},
			10,
			3
		);

		self::assertSame( 'Activate the licence.', AB_MCP_Guidance::unavailable_tool( 'wp_undo' )->get_error_message() );
		self::assertStringStartsWith( 'The tool "wp_deploy_list" is not on this site', AB_MCP_Guidance::unavailable_tool( 'wp_deploy_list' )->get_error_message(), 'An empty answer keeps the core\'s.' );
		self::assertSame( array( array( 'wp_undo', 'pro', true ), array( 'wp_deploy_list', 'agency', true ) ), $seen );
		self::assertSame( 'ab_mcp_needs_pro', AB_MCP_Guidance::unavailable_tool( 'wp_undo' )->get_error_code() );
	}

	public function testTheListsHoldNoToolOfThisPluginAndNothingTwice(): void {
		$free = array_keys( self::free_registry()->all() );
		$pro  = AB_MCP_Guidance::PRO_TOOLS;
		$agcy = AB_MCP_Guidance::AGENCY_TOOLS;

		self::assertSame( array(), array_values( array_intersect( $free, array_merge( $pro, $agcy ) ) ), 'A tool of this plugin runs here; it is never answered as one of Pro.' );
		self::assertSame( array(), array_values( array_intersect( $pro, $agcy ) ) );
		foreach ( array( $pro, $agcy ) as $list ) {
			self::assertSame( array_values( array_unique( $list ) ), $list );
			$sorted = $list;
			sort( $sorted );
			self::assertSame( $sorted, $list, 'Sorted, so a diff against Pro reads easily.' );
			foreach ( $list as $name ) {
				self::assertMatchesRegularExpression( '/^wp_[a-z_]+$/', $name );
			}
		}
		self::assertCount( 95, $pro, 'Pro 4.6.0 registers 103 tools of its own, 8 of them only with the Agency plan.' );
		self::assertSame( array( 'wp_blueprint_apply', 'wp_deploy_delete', 'wp_deploy_diff', 'wp_deploy_list', 'wp_deploy_push_file', 'wp_deploy_push_zip', 'wp_deploy_read', 'wp_deploy_test' ), $agcy, 'Applying a blueprint and Site Deploy.' );
		self::assertContains( 'wp_blueprint_diff', $pro, 'Reading a blueprint stays in Pro.' );
		foreach ( array( 'wp_undo', 'wp_run_ability', 'wp_list_abilities', 'wp_update_builder_element', 'wp_search_replace', 'wp_db_query', 'wp_read_plugin_file', 'wp_wc_list_orders' ) as $name ) {
			self::assertContains( $name, $pro );
		}
	}

	public function testNothingOfProIsRegisteredOrListedHere(): void {
		$r     = self::free_registry();
		$m     = new ReflectionMethod( AB_MCP_REST_Controller::class, 'tools_list' );
		$names = array_column( $m->invoke( new AB_MCP_REST_Controller( $r ) )['tools'], 'name' );

		foreach ( array_merge( AB_MCP_Guidance::PRO_TOOLS, AB_MCP_Guidance::AGENCY_TOOLS ) as $name ) {
			self::assertNull( $r->get( $name ), $name . ' is not a tool of this plugin.' );
			self::assertNotContains( $name, $names );
		}
	}

	/* ----------------------------------------------- the instructions */

	public function testWithoutProTheInstructionsSayWhatProAddsAndWhere(): void {
		$m         = new ReflectionMethod( AB_MCP_REST_Controller::class, 'instructions' );
		$paragraph = AB_MCP_Guidance::instructions_paragraph();

		self::assertStringContainsString( ' ' . $paragraph, $m->invoke( new AB_MCP_REST_Controller( self::free_registry() ) ) );
		self::assertStringStartsWith( 'NOT ON THIS SITE: the tools of AlphaBridge MCP Pro, a separate plugin.', $paragraph );
		self::assertStringContainsString( 'its Agency plan adds applying blueprints and Site Deploy over FTP/SFTP', $paragraph );
		self::assertStringStartsWith( 'The tool "wp_blueprint_apply" is not on this site: it belongs to the Agency plan', AB_MCP_Guidance::unavailable_tool( 'wp_blueprint_apply' )->get_error_message() );
		self::assertStringContainsString( 'tell them kindly that this site needs AlphaBridge MCP Pro for it and where it is: https://alphabridge-mcp.com/#pricing', $paragraph );
		self::assertLessThanOrEqual( 3, preg_match_all( '/[.!?](\s|$)/', $paragraph ), 'Short: it is loaded into every session.' );

		$r = self::free_registry();
		$r->register( 'wp_undo', array( 'capability' => 'manage_options' ) );
		self::assertStringNotContainsString( 'NOT ON THIS SITE', $m->invoke( new AB_MCP_REST_Controller( $r ) ), 'Where Pro\'s tools run, nothing about it.' );
	}

	public function testTheParagraphStaysAheadOfTheWriteAccessSentence(): void {
		$m    = new ReflectionMethod( AB_MCP_REST_Controller::class, 'instructions' );
		$text = $m->invoke( new AB_MCP_REST_Controller( self::free_registry() ) );

		self::assertLessThan( strpos( $text, 'WRITE ACCESS IS OFF' ), strpos( $text, 'NOT ON THIS SITE' ) );
		self::assertStringEndsWith( \AB_MCP_Site_Mode::instructions_sentence(), $text, 'The sentence on write access stays last, whatever an add-on adds.' );
	}

	public function testThePricingPageIsTheOneOfTheWebsite(): void {
		self::assertSame( 'https://alphabridge-mcp.com/#pricing', AB_MCP_Guidance::PRICING_URL );
	}

	/* ------------------------------------------------- refusals for the role */

	/**
	 * Refusals with the code ab_mcp_forbidden that are no matter of the
	 * account's role but of the call, which the assistant corrects itself:
	 * file => the start of the message.
	 */
	private const FORBIDDEN_BY_THE_CALL = array(
		'tools/class-tools-search-bulk.php' => 'Revisions cannot be searched; use wp_list_revisions',
		'tools/class-tools-content.php'     => 'Revisions are listed per post, and only for an account that may edit that post: pass its id as parent.',
		'tools/class-tools-media.php'       => 'You cannot attach media to that post: it does not exist, or your account may not edit it. Leave post_id out',
	);

	public function testEveryRefusalForTheRoleLeadsToUsersAndAsksToPassItOn(): void {
		$root   = dirname( __DIR__ ) . '/includes/';
		$plain  = array();
		$routed = 0;
		foreach ( array_merge( glob( $root . '*.php' ), glob( $root . '*/*.php' ), glob( $root . '*/*/*.php' ) ) as $file ) {
			$rel = substr( $file, strlen( $root ) );
			$src = (string) file_get_contents( $file );
			$routed += preg_match_all( '/AB_MCP_Guidance::role_refusal\(/', $src );
			if ( 'class-security.php' === $rel ) {
				continue; // The gate composes its own, with the capability (testEveryRefusalOfThePolicyGate…).
			}
			preg_match_all( "/new WP_Error\(\s*'ab_mcp_forbidden',\s*(?:__\(\s*)?'((?:[^'\\\\]|\\\\.)*)'/", $src, $m );
			foreach ( $m[1] as $message ) {
				$allowed = isset( self::FORBIDDEN_BY_THE_CALL[ $rel ] ) && 0 === strpos( stripcslashes( $message ), self::FORBIDDEN_BY_THE_CALL[ $rel ] );
				if ( ! $allowed ) {
					$plain[] = $rel . ': ' . $message;
				}
			}
			self::assertSame( 0, preg_match( "/new WP_Error\(\s*'ab_mcp_forbidden',\s*\\$/", $src ), $rel . ': a refusal built from a variable goes through role_refusal() too.' );
		}
		self::assertSame( array(), $plain, 'Every refusal for the role goes through AB_MCP_Guidance::role_refusal().' );
		self::assertGreaterThan( 28, $routed, 'The scan finds them.' );
	}

	public function testARefusalForTheRoleNamesTheAccountTheWayAndTheLink(): void {
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$users                           = 'https://example.test/wp-admin/users.php';
		$GLOBALS['ab_test_can']          = static fn( string $cap ): bool => 'read' === $cap;

		$create = AB_MCP_Tools_Content::create_post( array( 'type' => 'post', 'title' => 'X' ) );
		self::assertSame( 'ab_mcp_forbidden', $create->get_error_code() );
		self::assertStringStartsWith( 'Your account cannot create entries of this post type.', $create->get_error_message() );
		self::assertStringContainsString( 'If the account "user3" should be allowed to do this, an administrator can give it a role that allows it under Users.', $create->get_error_message() );
		self::assertLeadsTo( $users, $create->get_error_message() );

		ab_test_add_post( 12, array( 'post_status' => 'publish' ) );
		$update = AB_MCP_Tools_Content::update_post( array( 'id' => 12, 'title' => 'Y' ) );
		self::assertSame( 'ab_mcp_forbidden', $update->get_error_code() );
		self::assertStringStartsWith( 'Your account cannot edit this specific post.', $update->get_error_message() );
		self::assertLeadsTo( $users, $update->get_error_message() );

		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'publish_posts' !== $cap;
		$publish                = AB_MCP_Tools_Content::create_post( array( 'type' => 'post', 'title' => 'X', 'status' => 'publish' ) );
		self::assertSame( 'ab_mcp_forbidden', $publish->get_error_code() );
		self::assertStringStartsWith( 'You cannot publish; use status "draft" or "pending"', $publish->get_error_message(), 'A message built in a variable, too.' );
		self::assertLeadsTo( $users, $publish->get_error_message() );
	}

	public function testOneAnswerReadsInOneLanguage(): void {
		self::assertSame( AB_MCP_Guidance::HANDOVER, AB_MCP_Guidance::handover(), 'The English handover is the text handover() translates.' );
		$en = AB_MCP_Guidance::compose( 'Reason.', 'Steps.', 'https://example.test/x', false );
		self::assertSame( 'Reason. Steps. Direct link: https://example.test/x ' . AB_MCP_Guidance::HANDOVER, $en );
		self::assertSame( 'Reason. Direct link: https://example.test/x ' . AB_MCP_Guidance::HANDOVER, AB_MCP_Guidance::compose( 'Reason.', '', 'https://example.test/x' ), 'An empty part is left out.' );

		// With the German texts loaded, the core's own parts read German; an
		// add-on whose reason is English asks for the English frame.
		$GLOBALS['ab_test_translations'] = array(
			'Direct link: %s'            => 'Direkter Link: %s',
			AB_MCP_Guidance::HANDOVER    => 'Gib das freundlich weiter.',
			'If the account "%s" should be allowed to do this, an administrator can give it a role that allows it under Users.' => 'Soll das Konto „%s“ das dürfen, …',
		);
		self::assertSame( 'Reason. Steps. Direkter Link: https://example.test/x Gib das freundlich weiter.', AB_MCP_Guidance::compose( 'Reason.', 'Steps.', 'https://example.test/x' ) );
		self::assertSame( $en, AB_MCP_Guidance::compose( 'Reason.', 'Steps.', 'https://example.test/x', false ) );
		$role = AB_MCP_Guidance::role_refusal( 'Your account cannot edit this user.', array( 'translate' => false ) )->get_error_message();
		self::assertStringNotContainsString( 'Direkter', $role );
		self::assertStringNotContainsString( 'Soll das Konto', $role );
		self::assertStringEndsWith( AB_MCP_Guidance::HANDOVER, $role );
	}
}
