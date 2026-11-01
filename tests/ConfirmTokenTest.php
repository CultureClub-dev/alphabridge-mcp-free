<?php
/**
 * The technical confirmation for tools whose effect cannot be undone (9a):
 * the first call runs nothing and answers with a preview and a token; only
 * the same call with that token runs the tool, while nothing it covers has
 * changed, for the same user and connection scope, within ten minutes.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use AB_MCP_Auth;
use AB_MCP_Confirm_Token;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use WP_Error;
use WP_REST_Request;

final class ConfirmTokenTest extends TestCase {

	/** @var int How often the confirming tool really ran. */
	private int $runs = 0;

	/** @var string What the state callback reports. */
	private string $state = 'plugin hello 1.0 active';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
		// «Staging first» (4.15.0) off: it would answer the first writing call instead of the tool; StagingGateTest covers it.
		update_option( 'ab_mcp_options', array( 'site_mode' => 'full', 'staging_first' => 'off' ) );
		$this->scope( 'full' );
		$this->runs  = 0;
		$this->state = 'plugin hello 1.0 active';
	}

	protected function tearDown(): void {
		$this->scope( 'full' );
	}

	private function scope( string $scope ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, $scope );
	}

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'plugins', 'Plugins' );
		$r->register(
			'wp_delete_plugin',
			array(
				'description' => 'Delete a plugin.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'plugin' ),
					'properties' => array( 'plugin' => array( 'type' => 'string' ) ),
				),
				'confirm'     => array(
					'state'   => fn( array $a ): string => $this->state . '|' . $a['plugin'],
					'preview' => static fn( array $a ): array => array( 'would_delete' => $a['plugin'] ),
				),
				'callback'    => function ( array $a ): array {
					++$this->runs;
					return array( 'deleted' => $a['plugin'], 'args' => array_keys( $a ) );
				},
			)
		);
		$r->register(
			'wp_delete_missing',
			array(
				'description' => 'State refuses.',
				'confirm'     => array( 'state' => static fn(): WP_Error => new WP_Error( 'ab_mcp_not_installed', 'Plugin «x» is not installed.' ) ),
				'callback'    => function (): string {
					++$this->runs;
					return 'ran';
				},
			)
		);
		return $r;
	}

	/** @param array<string,mixed> $arguments */
	private function call( string $tool, array $arguments ): array {
		$request = new WP_REST_Request();
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool,
					'arguments' => $arguments,
				),
			)
		);
		$data = ( new AB_MCP_REST_Controller( $this->registry() ) )->handle( $request )->get_data();
		return $data['result'];
	}

	/** @return array<string,mixed> */
	private static function payload( array $result ): array {
		return json_decode( (string) $result['content'][0]['text'], true ) ?? array();
	}

	/* ------------------------------------------------------- through tools/call */

	public function testTheFirstCallRunsNothingAndAnswersWithPreviewAndToken(): void {
		$result = $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php' ) );

		self::assertFalse( $result['isError'] );
		self::assertSame( 0, $this->runs );
		$p = self::payload( $result );
		self::assertTrue( $p['confirm_required'] );
		self::assertFalse( $p['done'] );
		self::assertSame( array( 'would_delete' => 'hello/hello.php' ), $p['preview'] );
		self::assertMatchesRegularExpression( '/^\d+\.[0-9a-f]{64}$/', $p['confirm_token'] );
		self::assertSame( 600, $p['expires_in'] );
	}

	public function testTheSecondCallWithTheTokenRunsOnceAndTheToolNeverSeesTheToken(): void {
		$token  = self::payload( $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php' ) ) )['confirm_token'];
		$result = $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php', 'confirm_token' => $token ) );

		self::assertFalse( $result['isError'] );
		self::assertSame( 1, $this->runs );
		self::assertSame( array( 'plugin' ), self::payload( $result )['args'] );
	}

	public function testATokenForOtherArgumentsIsRefusedAndNothingRuns(): void {
		$token  = self::payload( $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php' ) ) )['confirm_token'];
		$result = $this->call( 'wp_delete_plugin', array( 'plugin' => 'akismet/akismet.php', 'confirm_token' => $token ) );

		self::assertTrue( $result['isError'] );
		self::assertSame( 0, $this->runs );
		self::assertStringContainsString( 'Nothing was changed', $result['content'][0]['text'] );
	}

	public function testATokenIsRefusedOnceWhatItCoversHasChanged(): void {
		$token       = self::payload( $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php' ) ) )['confirm_token'];
		$this->state = 'plugin hello 1.1 active';
		$result      = $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php', 'confirm_token' => $token ) );

		self::assertTrue( $result['isError'] );
		self::assertSame( 0, $this->runs );
	}

	public function testATokenIsBoundToTheUserAndToTheScopeOfTheConnection(): void {
		$token = self::payload( $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php' ) ) )['confirm_token'];

		ab_test_add_user( 4 );
		$GLOBALS['ab_test_current_user'] = 4;
		self::assertTrue( $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php', 'confirm_token' => $token ) )['isError'] );

		$GLOBALS['ab_test_current_user'] = 3;
		$this->scope( 'content' );
		$args = array( 'plugin' => 'hello/hello.php' );
		self::assertNotTrue( AB_MCP_Confirm_Token::check( 'wp_delete_plugin', $args, $this->state . '|hello/hello.php', $token, time() ) );
		self::assertSame( 0, $this->runs );
	}

	public function testAStateThatRefusesStopsTheCallBeforeAnyPreview(): void {
		$result = $this->call( 'wp_delete_missing', array() );

		self::assertTrue( $result['isError'] );
		self::assertSame( 0, $this->runs );
		self::assertStringContainsString( 'not installed', $result['content'][0]['text'] );
	}

	public function testTheSchemaAndTheDescriptionAnnounceTheTwoSteps(): void {
		$def = $this->registry()->get( 'wp_delete_plugin' );

		self::assertArrayHasKey( 'confirm_token', (array) $def['inputSchema']['properties'] );
		self::assertSame( array( 'plugin' ), $def['inputSchema']['required'], 'The token is never required: the first call goes without.' );
		self::assertStringContainsString( 'two steps', $def['description'] );
	}

	public function testArgumentsWithoutACanonicalFormAreRefusedNotBoundToAnEmptyString(): void {
		$result = $this->call( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php', 'x' => INF ) );

		self::assertTrue( $result['isError'] );
		self::assertSame( 0, $this->runs );
		self::assertStringContainsString( 'cannot be written as JSON', $result['content'][0]['text'] );

		$args = array( 'plugin' => 'hello/hello.php', 'x' => INF );
		self::assertSame( '', AB_MCP_Confirm_Token::issue( 'wp_delete_plugin', $args, 's', 1_000_000 ) );
		$token = AB_MCP_Confirm_Token::issue( 'wp_delete_plugin', array( 'plugin' => 'hello/hello.php' ), 's', 1_000_000 );
		self::assertInstanceOf( WP_Error::class, AB_MCP_Confirm_Token::check( 'wp_delete_plugin', $args, 's', $token, 1_000_000 ) );
	}

	public function testABrokenConfirmDefinitionRefusesInsteadOfRunningUnconfirmed(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'plugins', 'Plugins' );
		$ran = false;
		$r->register(
			'wp_delete_broken',
			array(
				'confirm'  => array( 'state' => 'no_such_function_anywhere' ),
				'callback' => static function () use ( &$ran ): string {
					$ran = true;
					return 'ran';
				},
			)
		);
		$request = new WP_REST_Request();
		$request->set_json_params( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_delete_broken', 'arguments' => array() ) ) );
		$result = ( new AB_MCP_REST_Controller( $r ) )->handle( $request )->get_data()['result'];

		self::assertTrue( $result['isError'] );
		self::assertFalse( $ran );

		foreach ( array( array(), false, '' ) as $empty ) {
			self::assertTrue( AB_MCP_Confirm_Token::required( array( 'confirm' => $empty ) ), var_export( $empty, true ) );
			self::assertInstanceOf( WP_Error::class, AB_MCP_Confirm_Token::gate( 't', array( 'confirm' => $empty ), array() ) );
		}
		self::assertFalse( AB_MCP_Confirm_Token::required( array() ) );
	}

	public function testAPreviewThatThrowsEndsInAControlledErrorWithoutItsText(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'plugins', 'Plugins' );
		$r->register(
			'wp_delete_throwing',
			array(
				'confirm'  => array( 'state' => static function (): string {
					throw new \RuntimeException( 'secret path /var/www/x' );
				} ),
				'callback' => static fn(): string => 'ran',
			)
		);
		$request = new WP_REST_Request();
		$request->set_json_params( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_delete_throwing', 'arguments' => array() ) ) );
		$result = ( new AB_MCP_REST_Controller( $r ) )->handle( $request )->get_data()['result'];

		self::assertTrue( $result['isError'] );
		self::assertStringNotContainsString( '/var/www', $result['content'][0]['text'] );
		self::assertStringContainsString( 'nothing ran', $result['content'][0]['text'] );
	}

	/* ---------------------------------------------------------- the token itself */

	public function testTheTokenExpiresAfterTenMinutes(): void {
		$args  = array( 'plugin' => 'hello/hello.php' );
		$token = AB_MCP_Confirm_Token::issue( 'wp_delete_plugin', $args, 's', 1_000_000 );

		self::assertTrue( AB_MCP_Confirm_Token::check( 'wp_delete_plugin', $args, 's', $token, 1_000_600 ) );
		$late = AB_MCP_Confirm_Token::check( 'wp_delete_plugin', $args, 's', $token, 1_000_601 );
		self::assertInstanceOf( WP_Error::class, $late );
		self::assertSame( 'ab_mcp_confirm_expired', $late->get_error_code() );
	}

	public function testAnExpiryPushedIntoTheFutureDoesNotPass(): void {
		$args  = array( 'plugin' => 'hello/hello.php' );
		$token = AB_MCP_Confirm_Token::issue( 'wp_delete_plugin', $args, 's', 1_000_000 );
		[ , $mac ] = explode( '.', $token );

		$forged = AB_MCP_Confirm_Token::check( 'wp_delete_plugin', $args, 's', '9999999999.' . $mac, 1_000_000 );
		self::assertInstanceOf( WP_Error::class, $forged );
		$shifted = AB_MCP_Confirm_Token::check( 'wp_delete_plugin', $args, 's', '1000500.' . $mac, 1_000_000 );
		self::assertSame( 'ab_mcp_confirm_mismatch', $shifted->get_error_code(), 'The expiry is inside the MAC.' );
	}

	public function testTheOrderOfKeysDoesNotMatterButTheOrderOfAListDoes(): void {
		$token = AB_MCP_Confirm_Token::issue( 't', array( 'a' => 1, 'b' => array( 'y' => 2, 'x' => 1 ) ), 's', 1_000_000 );
		self::assertTrue( AB_MCP_Confirm_Token::check( 't', array( 'b' => array( 'x' => 1, 'y' => 2 ), 'a' => 1 ), 's', $token, 1_000_000 ) );

		$list = AB_MCP_Confirm_Token::issue( 't', array( 'ids' => array( 1, 2 ) ), 's', 1_000_000 );
		self::assertInstanceOf( WP_Error::class, AB_MCP_Confirm_Token::check( 't', array( 'ids' => array( 2, 1 ) ), 's', $list, 1_000_000 ) );
	}

	public function testATokenForOneToolDoesNotConfirmAnother(): void {
		$args  = array( 'plugin' => 'hello/hello.php' );
		$token = AB_MCP_Confirm_Token::issue( 'wp_deactivate_plugin', $args, 's', 1_000_000 );

		self::assertInstanceOf( WP_Error::class, AB_MCP_Confirm_Token::check( 'wp_delete_plugin', $args, 's', $token, 1_000_000 ) );
	}

	public function testSomethingThatIsNotATokenIsRefusedWithAWayOn(): void {
		foreach ( array( 'yes', '123', '123.abc', str_repeat( 'f', 64 ) ) as $bogus ) {
			$no = AB_MCP_Confirm_Token::check( 't', array(), 's', $bogus, 1_000_000 );
			self::assertSame( 'ab_mcp_confirm_invalid', $no->get_error_code(), $bogus );
			self::assertStringContainsString( 'Leave out confirm_token', $no->get_error_message() );
		}
	}

	/* ------------------------------------- a tool that confirms some calls (4.17.0) */

	/** @var mixed What `when` answers; a Throwable is thrown instead. */
	private $needs = true;

	/** @var string[] The callbacks of the definition, in the order they ran. */
	private array $calls = array();

	/** @var mixed What `preview` answers. */
	private $preview_answer = array( 'would_write' => true );

	/** @var mixed The `when` of the definition. */
	private $when = null;

	/** @var array<string,mixed>|null The arguments `when` was given. */
	private ?array $when_args = null;

	/**
	 * A tool as Pro's wp_update_option will be: it confirms only the calls
	 * `when` says need it.
	 *
	 * @param array<string,mixed> $extra Further keys of `confirm`.
	 */
	private function conditional_registry( array $extra = array() ): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'settings', 'Settings' );
		$confirm = array(
			'when'    => $this->when ?? function ( array $a ) {
				$this->calls[]   = 'when';
				$this->when_args = $a;
				if ( $this->needs instanceof \Throwable ) {
					throw $this->needs;
				}
				return $this->needs;
			},
			'state'   => function ( array $a ) {
				$this->calls[] = 'state';
				return $this->state;
			},
			'preview' => function ( array $a ) {
				$this->calls[] = 'preview';
				return $this->preview_answer;
			},
		) + $extra;
		$r->register(
			'wp_update_option',
			array(
				'description' => 'Write an option.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array( 'name' => array( 'type' => 'string' ) ),
				),
				'confirm'     => $confirm,
				'callback'    => function ( array $a ): array {
					$this->calls[] = 'callback';
					++$this->runs;
					return array( 'written' => $a['name'], 'args' => array_keys( $a ) );
				},
			)
		);
		return $r;
	}

	/** @param array<string,mixed> $arguments */
	private function call_conditional( array $arguments, ?AB_MCP_Tool_Registry $r = null ): array {
		$request = new WP_REST_Request();
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_update_option', 'arguments' => $arguments ),
			)
		);
		return ( new AB_MCP_REST_Controller( $r ?? $this->conditional_registry() ) )->handle( $request )->get_data()['result'];
	}

	public function testWhenFalseRunsTheToolAtOnceWithoutStateOrPreview(): void {
		$this->needs = false;
		$result      = $this->call_conditional( array( 'name' => 'blogname' ) );

		self::assertFalse( $result['isError'] );
		self::assertSame( 1, $this->runs );
		self::assertSame( array( 'when', 'callback' ), $this->calls, 'state and preview are not asked at all' );
		self::assertSame( 'blogname', self::payload( $result )['written'] );
		self::assertArrayNotHasKey( 'confirm_required', self::payload( $result ) );
	}

	public function testWhenFalseDropsATokenSentAlongAndHandsWhenTheArgumentsWithoutIt(): void {
		$this->needs = false;
		$result      = $this->call_conditional( array( 'name' => 'blogname', 'confirm_token' => '1.' . str_repeat( 'a', 64 ) ) );

		self::assertFalse( $result['isError'] );
		self::assertSame( array( 'name' ), self::payload( $result )['args'] );
		self::assertSame( array( 'name' => 'blogname' ), $this->when_args );
	}

	public function testWhenTrueAsksForTheTokenAsWithoutWhen(): void {
		$this->needs = true;
		$first       = self::payload( $this->call_conditional( array( 'name' => 'default_role' ) ) );

		self::assertTrue( $first['confirm_required'] );
		self::assertSame( 0, $this->runs );
		self::assertSame( array( 'when', 'state', 'preview' ), $this->calls, 'when is asked before state' );

		$this->calls = array();
		$second      = $this->call_conditional( array( 'name' => 'default_role', 'confirm_token' => $first['confirm_token'] ) );
		self::assertFalse( $second['isError'] );
		self::assertSame( 1, $this->runs );
		self::assertSame( array( 'when', 'state', 'callback' ), $this->calls );
	}

	public function testAnythingButAStrictFalseAsksForTheToken(): void {
		foreach ( array( 0, '', null, '0', 'no', array(), new WP_Error( 'x', 'y' ), 0.0 ) as $answer ) {
			$this->needs = $answer;
			$this->calls = array();
			$p           = self::payload( $this->call_conditional( array( 'name' => 'blogname' ) ) );
			self::assertTrue( $p['confirm_required'] ?? false, var_export( $answer, true ) );
			self::assertSame( 0, $this->runs, var_export( $answer, true ) );
		}
	}

	public function testAWhenThatThrowsAsksForTheTokenInsteadOfFailing(): void {
		$this->needs = new \RuntimeException( 'secret path /var/www/x' );
		$result      = $this->call_conditional( array( 'name' => 'default_role' ) );

		self::assertFalse( $result['isError'] );
		self::assertTrue( self::payload( $result )['confirm_required'] );
		self::assertSame( 0, $this->runs );
		self::assertSame( array( 'when', 'state', 'preview' ), $this->calls );
	}

	public function testAWhenThatCannotBeCalledIsNoConditionAtAll(): void {
		$this->when = 'no_such_function_anywhere';
		$r          = $this->conditional_registry();
		$result     = $this->call_conditional( array( 'name' => 'blogname' ), $r );

		self::assertTrue( self::payload( $result )['confirm_required'] );
		self::assertSame( 0, $this->runs );
		self::assertStringContainsString( 'Runs in two steps', $r->get( 'wp_update_option' )['description'], 'and the description does not claim one' );
	}

	public function testWhenFalseRunsEvenWhereTheStateWouldRefuse(): void {
		$this->needs = false;
		$r           = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'settings', 'Settings' );
		$r->register(
			'wp_update_option',
			array(
				'confirm'  => array(
					'when'  => static fn(): bool => false,
					'state' => static fn(): WP_Error => new WP_Error( 'ab_mcp_blocked', 'Only for the pair.' ),
				),
				'callback' => function ( array $a ): string {
					++$this->runs;
					return 'ran';
				},
			)
		);
		self::assertFalse( $this->call_conditional( array( 'name' => 'blogname' ), $r )['isError'] );
		self::assertSame( 1, $this->runs );
	}

	public function testAPreviewOrAStateThatRefusesGivesNeitherPreviewNorToken(): void {
		$this->needs          = true;
		$this->preview_answer = new WP_Error( 'ab_mcp_probe_failed', 'The new address did not answer.' );
		$result               = $this->call_conditional( array( 'name' => 'default_role' ) );

		self::assertTrue( $result['isError'] );
		self::assertStringContainsString( 'did not answer', $result['content'][0]['text'] );
		self::assertStringNotContainsString( 'confirm_token', $result['content'][0]['text'] );
		self::assertSame( 0, $this->runs );

		$gate = AB_MCP_Confirm_Token::gate( 't', array( 'confirm' => array( 'state' => static fn(): WP_Error => new WP_Error( 'no', 'No.' ) ) ), array() );
		self::assertInstanceOf( WP_Error::class, $gate );
	}

	public function testTheNoteDescribesAConditionalToolInPlaceOfTheTwoSteps(): void {
		$note = 'Writing users_can_register or default_role runs in two steps; every other option is written at once.';
		$def  = $this->conditional_registry( array( 'note' => '  ' . $note . ' ' ) )->get( 'wp_update_option' );

		self::assertStringStartsWith( 'Write an option. ' . $note . ' ', $def['description'] );
		self::assertStringNotContainsString( 'Runs in two steps', $def['description'] );
		$prop = (array) $def['inputSchema']['properties'];
		self::assertStringContainsString( 'for a call that needs one', $prop['confirm_token']['description'] );
		self::assertStringContainsString( 'not used up', $prop['confirm_token']['description'] );
		self::assertSame( array( 'name' ), $def['inputSchema']['required'], 'The token is never required.' );

		$plain = $this->conditional_registry()->get( 'wp_update_option' );
		self::assertStringContainsString( 'Some calls run in two steps', $plain['description'], 'Without a note, a sentence that claims two steps only for some calls.' );

		// A tool that confirms every call keeps its sentence, and a note follows it.
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'plugins', 'Plugins' );
		$r->register( 'wp_delete_noted', array( 'description' => 'Delete.', 'confirm' => array( 'state' => static fn(): string => 's', 'note' => 'The files go too.' ) ) );
		$noted = $r->get( 'wp_delete_noted' );
		self::assertStringContainsString( 'Runs in two steps', $noted['description'] );
		self::assertStringContainsString( 'reversible. The files go too.', $noted['description'] );
		self::assertStringNotContainsString( 'for a call that needs one', ( (array) $noted['inputSchema']['properties'] )['confirm_token']['description'] );
		self::assertSame( AB_MCP_Confirm_Token::schema_property(), AB_MCP_Confirm_Token::schema_property( array( 'state' => 'x' ) ), 'A caller of before gets the sentence of before.' );
		self::assertSame( AB_MCP_Confirm_Token::description_note(), AB_MCP_Confirm_Token::description_note( array( 'state' => 'x', 'note' => 42 ) ) );
	}

	public function testAChangeThenItsStateBackAndTheSameTokenConfirmsTheSameChangeAgain(): void {
		// The contract: a token confirms this change to this state for ten
		// minutes and is not used up. An undo that brings the state of the
		// preview back makes the same token valid for the same change again.
		$this->needs = true;
		$this->state = 'default_role=subscriber|caps=read';
		$token       = self::payload( $this->call_conditional( array( 'name' => 'default_role' ) ) )['confirm_token'];

		self::assertFalse( $this->call_conditional( array( 'name' => 'default_role', 'confirm_token' => $token ) )['isError'] );
		self::assertSame( 1, $this->runs );

		$this->state = 'default_role=author|caps=read,edit_posts';   // what the change left
		$again       = $this->call_conditional( array( 'name' => 'default_role', 'confirm_token' => $token ) );
		self::assertTrue( $again['isError'] );
		self::assertStringContainsString( 'what it covers has changed', $again['content'][0]['text'] );
		self::assertSame( 1, $this->runs );

		$this->state = 'default_role=subscriber|caps=read';           // undone
		self::assertFalse( $this->call_conditional( array( 'name' => 'default_role', 'confirm_token' => $token ) )['isError'] );
		self::assertSame( 2, $this->runs );
	}
}
