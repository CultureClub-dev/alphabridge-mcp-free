<?php
/**
 * The way to a staging site in the server instructions and the guided
 * prompt staging_workflow (free since 4.15.0).
 *
 * What must hold:
 *
 * - While wp_staging_status is registered and switched on, the server
 *   instructions (initialize and server/discover) tell the assistant to
 *   call it before the first change and, unless it reports the role
 *   staging or a pair, to ask the person whether to work on a staging site
 *   first: always with the reason and the way, offering to set staging up
 *   now and to continue there. Without the tool, or with it switched off,
 *   not a word of it; whether the preview protection tool is on does not
 *   matter.
 * - The sentence stands before the filter of the add-ons and is short: the
 *   instructions go with every connection.
 * - The prompt staging_workflow comes with the tool, once, with its two
 *   optional arguments and its steps, through prompts/list and prompts/get;
 *   not without the tool in the registry the server answers with, not with
 *   it switched off, not without a registry; other prompts stay as they are.
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
	 * to continue there.
	 */
	const SENTENCE = ' Before the first change on this site, call wp_staging_status. Unless it reports the role staging or a pair,'
		. ' ask the person whether to work on staging first, always saying why (a change here is visible to visitors at once;'
		. ' on staging it is checked first, then carried over) and how (the host\'s staging function, a staging plugin,'
		. ' connecting an existing staging site, or drafts for content alone; its checklist has the steps), and offer to set up'
		. ' staging now and continue there (prompt staging_workflow). If the person declines, go ahead here.';

	/** What would advertise an edition, as the staging tool's own test reads it, and what would link anything. */
	const ADVERTISING = '/\bPro\b|Agency|upgrade|licen[cs]e|price|pricing|checkout|freemius|https?:\/\/|alphabridge-mcp\.com/i';

	protected function setUp(): void {
		ab_test_reset();
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
	}

	/** The tools of the group «Staging», as the plugin registers them. */
	private static function staging_registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
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

	/* ------------------------------------------------- the instructions */

	public function testWhileTheStatusToolIsOnTheInstructionsAskForStagingWithReasonAndWay(): void {
		$r = self::staging_registry();

		self::assertStringContainsString( self::SENTENCE, self::instructions( $r ) );
		self::assertStringContainsString( self::SENTENCE, self::server( $r, 'initialize', array() )['instructions'], 'In what initialize hands every client.' );
	}

	public function testNotAWordOfItWithoutTheTool(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->register( 'wp_get_post', array( 'capability' => 'read', 'readonly' => true ) );

		self::assertStringNotContainsStringIgnoringCase( 'staging', self::instructions( $r ), 'It would point to a tool that is not there.' );
		self::assertStringNotContainsStringIgnoringCase( 'staging', self::instructions( new AB_MCP_Tool_Registry() ) );
	}

	public function testNotAWordOfItWhileTheToolIsSwitchedOff(): void {
		AB_MCP_Settings::set_tool_state( array( 'wp_staging_status' => false ) );
		self::assertStringNotContainsStringIgnoringCase( 'staging', self::instructions( self::staging_registry() ) );

		AB_MCP_Settings::set_tool_state( array( 'wp_set_preview_protection' => false ) );
		self::assertStringContainsString( self::SENTENCE, self::instructions( self::staging_registry() ), 'Only the status tool decides: the steps name the settings page as the other way to the protection.' );
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
			'the status after every step'           => 'Call it again after every step below',
			'the way: host'                         => 'the host\'s staging function',
			'the way: plugin'                       => 'a staging plugin',
			'the way: an existing staging site'     => 'an existing staging site connected to this one',
			'the way: drafts'                       => 'drafts and previews published once approved',
			'nothing of the host it does not say'   => 'Name a host and its staging function only where wp_staging_status names them',
			'the preview protection'                => 'switch on the preview protection with wp_set_preview_protection (enabled=true), or on its settings page at Staging',
			'one object at a time'                  => 'Carry it over one object at a time. Read each changed page, post, menu, term or setting on the staging site and write it on the production site',
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

		self::assertSame( array(), apply_filters( 'ab_mcp_prompts', array(), new AB_MCP_Tool_Registry() ), 'The server answers with tools without it.' );
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
