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
		update_option( 'ab_mcp_options', array( 'site_mode' => 'full' ) );
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
		self::assertStringContainsString( 'Two steps', $def['description'] );
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
}
