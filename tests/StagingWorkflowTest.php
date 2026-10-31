<?php
/**
 * The way to a staging site in the server instructions and the guided
 * prompt staging_workflow (free since 4.15.0).
 *
 * What must hold:
 *
 * - While wp_staging_status is registered and switched on and the
 *   connection can write, the server instructions (initialize and
 *   server/discover) tell the assistant to call it before the first change
 *   and, unless it reports the role staging or a pair, to ask the person
 *   whether to work on a staging site first: always with the reason and
 *   the way, offering to set staging up now and to continue there. Without
 *   the tool, or with it switched off, not a word of it.
 * - A connection can write with write access on and a writing tool that
 *   tools/list shows it: switched on and allowed for the access level of
 *   its token. With write access off, a token that only reads, a token for
 *   content on a site whose only writing tool is no content tool, or every
 *   writing tool switched off: not a word of it, and no prompt.
 * - The sentence stands before the filter of the add-ons and is short: the
 *   instructions go with every connection.
 * - The prompt staging_workflow comes under the same condition, once, with
 *   its two optional arguments and its steps, through prompts/list and
 *   prompts/get; not without the tool in the registry the server answers
 *   with, not with it switched off, not without a registry; other prompts
 *   stay as they are. Its steps keep drafts to posts and pages, have
 *   wp_staging_status check the setting up only, cover a protection that
 *   is on already, and say that carrying over needs a connection that can
 *   write on both sites.
 * - The sentence, the prompt and the checklist of wp_staging_status keep
 *   drafts to the same content, posts and pages; the prompt and the
 *   checklist say that media, menus and settings take effect at once.
 * - Neither advertises anything: no Pro, Agency, upgrade, licence, price or
 *   checkout, and no link.
 *
 * The tests check text and condition, not what an assistant does with it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Auth;
use AB_MCP_REST_Controller;
use AB_MCP_Settings;
use AB_MCP_Staging;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';

final class StagingWorkflowTest extends TestCase {

	/**
	 * The sentence, word for word: the reason and the way every time
	 * (Thomas, 31.10.2026: «fragen und erläutern warum und wie, das ist
	 * wichtig immer mit begründung»), and the offer to set staging up and
	 * to continue there. Drafts only for posts and pages: media and the
	 * other changes take effect at once.
	 */
	const SENTENCE = ' Before the first change on this site, call wp_staging_status. Unless it reports the role staging or a pair,'
		. ' ask the person whether to work on staging first, always saying why (a change here is visible to visitors at once;'
		. ' on staging it is checked first, then carried over) and how (the host\'s staging function, a staging plugin,'
		. ' connecting an existing staging site, or drafts for posts and pages alone; its checklist has the steps), and offer to set up'
		. ' staging now and continue there (prompt staging_workflow). If the person declines, go ahead here.';

	/** What would advertise an edition, as the staging tool's own test reads it, and what would link anything. */
	const ADVERTISING = '/\bPro\b|Agency|upgrade|licen[cs]e|price|pricing|checkout|freemius|https?:\/\/|alphabridge-mcp\.com/i';

	protected function setUp(): void {
		ab_test_reset();
		self::scope( 'full' );
		AB_MCP_Settings::set( 'site_mode', 'full' );
	}

	protected function tearDown(): void {
		self::scope( 'full' );
	}

	/** The access level of the current connection, as AB_MCP_Auth records it after the token check. */
	private static function scope( string $scope ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, $scope );
	}

	/** The tools of the group «Staging», as the plugin registers them; its only writing tool needs manage_options. */
	private static function staging_registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r;
	}

	/** A writing tool for content, as a token with the access level Content may call it. */
	private static function with_content_writer( AB_MCP_Tool_Registry $r ): AB_MCP_Tool_Registry {
		$r->register(
			'wp_update_post',
			array(
				'capability' => 'edit_posts',
				'readonly'   => false,
			)
		);
		return $r;
	}

	private static function instructions( AB_MCP_Tool_Registry $r ): string {
		$m = new ReflectionMethod( AB_MCP_REST_Controller::class, 'instructions' );
		return $m->invoke( new AB_MCP_REST_Controller( $r ) );
	}

	/** One method of the server, as the endpoint answers it. */
	private static function server( AB_MCP_Tool_Registry $r, string $method, ...$args ) {
		$m = new ReflectionMethod( AB_MCP_REST_Controller::class, $method );
		return $m->invoke( new AB_MCP_REST_Controller( $r ), ...$args );
	}

	/** The prompt staging_workflow among the prompts, or null. */
	private static function prompt( array $prompts ): ?array {
		foreach ( $prompts as $prompt ) {
			if ( 'staging_workflow' === ( $prompt['name'] ?? '' ) ) {
				return $prompt;
			}
		}
		return null;
	}

	/** Neither the sentence nor the prompt, for this registry and connection. */
	private static function assertNotOffered( AB_MCP_Tool_Registry $r, string $why ): void {
		self::assertStringNotContainsStringIgnoringCase( 'staging', self::instructions( $r ), $why );
		self::assertNull( self::prompt( apply_filters( 'ab_mcp_prompts', array(), $r ) ), $why . ' No prompt either.' );
	}

	/** The sentence and the prompt, for this registry and connection. */
	private static function assertOffered( AB_MCP_Tool_Registry $r, string $why ): void {
		self::assertStringContainsString( self::SENTENCE, self::instructions( $r ), $why );
		self::assertNotNull( self::prompt( apply_filters( 'ab_mcp_prompts', array(), $r ) ), $why . ' The prompt too.' );
	}

	/* ------------------------------------------------- the instructions */

	public function testWhileTheStatusToolIsOnTheInstructionsAskForStagingWithReasonAndWay(): void {
		$r = self::staging_registry();

		self::assertStringContainsString( self::SENTENCE, self::instructions( $r ) );
		self::assertStringContainsString( self::SENTENCE, self::server( $r, 'initialize', array() )['instructions'], 'In what initialize hands every client.' );
	}

	public function testNotAWordOfItWithoutTheTool(): void {
		self::staging_registry(); // The prompt is hooked.
		$r = self::with_content_writer( new AB_MCP_Tool_Registry() );
		$r->register(
			'wp_get_post',
			array(
				'capability' => 'read',
				'readonly'   => true,
			)
		);

		self::assertNotOffered( $r, 'It would point to a tool that is not there, on a site that can write.' );
		self::assertNotOffered( new AB_MCP_Tool_Registry(), 'Nor on an empty one.' );
	}

	public function testNotAWordOfItWhileTheToolIsSwitchedOff(): void {
		AB_MCP_Settings::set_tool_state( array( 'wp_staging_status' => false ) );
		self::assertNotOffered( self::with_content_writer( self::staging_registry() ), 'Switched off under Fine-tuning.' );
	}

	public function testNotAWordOfItWithWriteAccessOff(): void {
		AB_MCP_Settings::set( 'site_mode', 'read' );
		self::assertNotOffered( self::with_content_writer( self::staging_registry() ), 'The question is about a change this site does not allow.' );
		self::assertStringContainsString( 'WRITE ACCESS IS OFF', self::instructions( self::staging_registry() ), 'The instructions say that instead.' );
	}

	public function testNotAWordOfItForATokenThatOnlyReads(): void {
		self::scope( 'read' );
		self::assertNotOffered( self::with_content_writer( self::staging_registry() ), 'tools/list shows this connection no writing tool.' );
	}

	public function testATokenForContentNeedsAWritingToolOfItsLevel(): void {
		self::scope( 'content' );
		self::assertNotOffered( self::staging_registry(), 'wp_set_preview_protection needs manage_options, which the access level Content does not reach.' );
		self::assertOffered( self::with_content_writer( self::staging_registry() ), 'With a tool for content it can write.' );
	}

	public function testEveryWritingToolSwitchedOffIsNoConnectionThatWrites(): void {
		AB_MCP_Settings::set_tool_state( array( 'wp_set_preview_protection' => false ) );
		self::assertNotOffered( self::staging_registry(), 'Its only writing tool is off.' );
		self::assertOffered( self::with_content_writer( self::staging_registry() ), 'One writing tool on is enough: the steps name the settings page as the other way to the protection.' );
	}

	public function testTheSentenceIsShortAndStandsBeforeTheFilterOfTheAddOns(): void {
		self::assertLessThanOrEqual( 3, preg_match_all( '/[.!?](\s|$)/', self::SENTENCE ), 'Short: it goes with every connection.' );
		self::assertLessThanOrEqual( 600, strlen( self::SENTENCE ) );

		add_filter( 'ab_mcp_instructions', static fn( string $text ): string => $text . ' ADD-ON.' );
		$text = self::instructions( self::staging_registry() );
		self::assertLessThan( strpos( $text, ' ADD-ON.' ), strpos( $text, self::SENTENCE ), 'Before the filter, so an add-on sees it and adds after it.' );
		self::assertLessThan( strpos( $text, ' NOT ON THIS SITE' ), strpos( $text, self::SENTENCE ), 'With the other lines on the tools, before the paragraph on what is not here.' );
	}

	/* ------------------------------------------------------- the prompt */

	public function testThePromptComesWithTheToolOnceWithItsArgumentsAndSteps(): void {
		$r = self::staging_registry();
		AB_MCP_Tools_Staging::register( new AB_MCP_Tool_Registry() );

		$prompts = apply_filters( 'ab_mcp_prompts', array(), $r );
		self::assertCount( 1, $prompts, 'Registered once.' );
		$hooked = array_values( array_filter( $GLOBALS['ab_test_filters']['ab_mcp_prompts'], static fn( array $e ): bool => array( AB_MCP_Tools_Staging::class, 'add_prompt' ) === $e['fn'] ) );
		self::assertSame( 2, $hooked[0]['args'], 'WordPress passes the registry only to a callback hooked for two arguments.' );
		$prompt = $prompts[0];
		self::assertSame( 'staging_workflow', $prompt['name'] );
		self::assertNotSame( '', $prompt['description'] );
		self::assertSame(
			array(
				array( 'change', false ),
				array( 'staging_site', false ),
			),
			array_map( static fn( array $a ): array => array( $a['name'], $a['required'] ), $prompt['arguments'] )
		);
		foreach ( $prompt['arguments'] as $argument ) {
			self::assertNotSame( '', $argument['description'] );
			self::assertStringContainsString( '{{' . $argument['name'] . '}}', $prompt['text'] );
		}

		preg_match_all( '/^(\d+)\. /m', $prompt['text'], $m );
		self::assertSame( array( '1', '2', '3', '4', '5', '6', '7', '8' ), $m[1], 'Eight steps, in order.' );
		$parts = array(
			'the reason'                            => 'a change on the production site is visible to visitors at once; on a staging site it is checked first and carried over once it is approved',
			'the status after setting up'           => 'Call it again after each step of setting up (steps 4 and 5), on the site you are working on: it checks the role, the pair, whether the staging site answers and its preview protection',
			'not the work'                          => 'It does not check the work or what was carried over: for that, read the objects on both sites with the reading tools.',
			'the way: host'                         => 'a staging copy made with the host\'s staging function',
			'the way: plugin'                       => 'or with a staging plugin',
			'the way: an existing staging site'     => 'or an existing staging site connected to this one',
			'the way: drafts, for posts and pages'  => 'For changes to posts and pages alone, drafts and previews published once approved are a way too',
			'not for media and the rest'            => 'media, menus, settings and the other changes take effect at once, so for them staging',
			'nothing of the host it does not say'   => 'Name a host and its staging function only where wp_staging_status names them',
			'the preview protection'                => 'switch on the preview protection with wp_set_preview_protection (enabled=true), or on its settings page at Staging',
			'a protection on already'               => 'If the protection is on already, the answer says changed=false and carries no password: ask the person for the password they have, or, only with their yes, set a new one with new_password=true, which signs every visitor out; say so first.',
			'one object at a time'                  => 'Carry it over one object at a time.',
			'a connection that writes on both'      => 'This needs a connection that can write on both sites; if one is missing or only reads, tell the person how to get it',
			'the way to that connection'            => 'connect you on the settings page of AlphaBridge MCP on that site (Connect your AI assistant) or add the site in the hub, with the access level Full (Content reaches posts, pages, media and categories only) and write access switched on there',
			'read there, write here'                => 'Read each changed page, post, menu, term or setting on the staging site and write it on the production site with the same tools',
			'undo where a tool records a point'     => 'Where a tool records an undo point, its answer carries an undo token, and wp_undo with it takes the change back',
			'and where it records none'             => 'where the answer has none, tell the person that this change has no way back through AlphaBridge',
			'the person decides'                    => 'If the person would rather not use staging, or chooses drafts, go ahead that way',
		);
		foreach ( $parts as $what => $part ) {
			self::assertStringContainsString( $part, $prompt['text'], $what );
		}
	}

	public function testThePromptIsListedAndFilledThroughTheServer(): void {
		$r = self::staging_registry();

		self::assertSame( array( 'listChanged' => false ), self::server( $r, 'capabilities' )['prompts'] ?? null, 'With the prompt the server offers prompts.' );
		$listed = self::prompt( self::server( $r, 'prompts_list' )['prompts'] );
		self::assertNotNull( $listed, 'prompts/list names it.' );
		self::assertSame( array( 'change', 'staging_site' ), array_column( $listed['arguments'], 'name' ) );
		self::assertArrayNotHasKey( 'text', $listed, 'The list carries no bodies.' );

		$got  = self::server( $r, 'prompts_get', 7, array( 'name' => 'staging_workflow', 'arguments' => array( 'change' => 'New opening hours on the contact page', 'staging_site' => 'https://staging.example.com' ) ) );
		$text = $got['result']['messages'][0]['content']['text'];
		self::assertStringContainsString( 'Change: New opening hours on the contact page. Staging site, if there is one already: https://staging.example.com', $text );

		$bare = self::server( $r, 'prompts_get', 8, array( 'name' => 'staging_workflow' ) )['result']['messages'][0]['content']['text'];
		self::assertStringNotContainsString( '{{', $bare, 'Arguments left out leave no placeholder.' );
	}

	public function testNoPromptWithoutTheToolWithItOffOrWithoutTheRegistry(): void {
		AB_MCP_Tools_Staging::register( new AB_MCP_Tool_Registry() );

		self::assertSame( array(), apply_filters( 'ab_mcp_prompts', array(), self::with_content_writer( new AB_MCP_Tool_Registry() ) ), 'The server answers with tools without it.' );
		self::assertSame( array(), apply_filters( 'ab_mcp_prompts', array() ), 'A caller that names no registry gets none.' );
		self::assertArrayNotHasKey( 'prompts', self::server( new AB_MCP_Tool_Registry(), 'capabilities' ), 'And the server offers no prompts.' );

		AB_MCP_Settings::set_tool_state( array( 'wp_staging_status' => false ) );
		self::assertSame( array(), apply_filters( 'ab_mcp_prompts', array(), self::staging_registry() ), 'Switched off.' );
		self::assertArrayNotHasKey( 'prompts', self::server( self::staging_registry(), 'capabilities' ) );
	}

	public function testOtherPromptsStayAsTheyAre(): void {
		$other = array(
			'name' => 'edit_builder_page',
			'text' => 'core steps',
		);

		$with = AB_MCP_Tools_Staging::add_prompt( array( $other ), self::staging_registry() );
		self::assertSame( $other, $with[0] );
		self::assertSame( 'staging_workflow', $with[1]['name'] );
		self::assertSame( array( $other ), AB_MCP_Tools_Staging::add_prompt( array( $other ), new AB_MCP_Tool_Registry() ) );
		self::assertSame( array(), AB_MCP_Tools_Staging::add_prompt( 'not a list', new AB_MCP_Tool_Registry() ) );
	}

	public function testTheChecklistThePromptAndTheSentenceKeepDraftsToPostsAndPages(): void {
		$items    = AB_MCP_Staging::checklist( AB_MCP_Staging::ROLE_PRODUCTION, null, null, false, array( 'name' => '' ) );
		$fallback = '';
		foreach ( $items as $item ) {
			if ( 'staging_connected' === $item['id'] ) {
				$fallback = $item['fallback'];
			}
		}
		$prompt = AB_MCP_Tools_Staging::add_prompt( array(), self::staging_registry() )[0]['text'];

		$scope = static function ( string $pattern, string $text ): string {
			self::assertSame( 1, preg_match( $pattern, $text, $m ), $text );
			return $m[1];
		};
		self::assertSame( 'posts and pages', $scope( '/drafts for (.+?) alone/', self::instructions( self::staging_registry() ) ), 'The sentence, as the server sends it.' );
		self::assertSame( 'posts and pages', $scope( '/For changes to (.+?) alone, drafts/', $prompt ), 'The prompt.' );
		self::assertSame( 'posts and pages', $scope( '/for changes to (.+?) alone, work in drafts/', $fallback ), 'The checklist the prompt sends the assistant to.' );
		foreach ( array( 'prompt' => $prompt, 'checklist' => $fallback ) as $what => $text ) {
			self::assertMatchesRegularExpression( '/media, menus,? (and )?settings[^.]* take effect at once/', $text, $what . ': what drafts do not cover, and why.' );
		}
	}

	/* --------------------------------------------- nothing advertised */

	public function testNeitherAdvertisesAnything(): void {
		$prompt = AB_MCP_Tools_Staging::add_prompt( array(), self::staging_registry() )[0];
		$texts  = array_merge(
			array( self::SENTENCE, $prompt['description'], $prompt['text'] ),
			array_column( $prompt['arguments'], 'description' )
		);
		foreach ( $texts as $text ) {
			self::assertDoesNotMatchRegularExpression( self::ADVERTISING, $text );
		}
	}
}
