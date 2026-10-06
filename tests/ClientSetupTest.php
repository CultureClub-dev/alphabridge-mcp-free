<?php
/**
 * What the settings page and the readmes tell a person who sets up a client.
 *
 * - After a connection is created, Claude Code gets one command, the one the
 *   guide on the website shows: `claude mcp add --transport http` with the
 *   endpoint and the token in the Authorization header. The config with
 *   mcp-remote and ${AUTH} is offered for Cursor only: Claude Code fills
 *   ${AUTH} from the shell, not from the config's env, and /mcp then reports
 *   AUTH missing.
 * - The note under the fine-tuning names the rate limit without «(fixed)».
 * - The readmes say what the audit log keeps, the latest calls up to its
 *   limit, and not that it records every call.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_Audit_Log;
use AB_MCP_Settings;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;

final class ClientSetupTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/** @param mixed ...$args */
	private function call( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin(), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	private static function file( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $name );
	}

	public function testClaudeCodeGetsACommandAndCursorTheConfig(): void {
		$x = $this->xpath( $this->call( 'reveal_html' ) );

		$cli = $x->query( '//input[contains(concat(" ", normalize-space(@class), " "), " ab-reveal-cli ")]' );
		self::assertSame( 1, $cli->length, 'One field for the command.' );
		self::assertTrue( $cli->item( 0 )->hasAttribute( 'readonly' ) );
		self::assertSame( 'Command for Claude Code', $cli->item( 0 )->getAttribute( 'aria-label' ) );
		self::assertSame( 1, $x->query( '//button[@data-copy-target=".ab-reveal-cli"]' )->length, 'The command can be copied.' );

		$json = $x->query( '//textarea[contains(concat(" ", normalize-space(@class), " "), " ab-reveal-json ")]' );
		self::assertSame( 1, $json->length );
		self::assertSame( 'Config for Cursor', $json->item( 0 )->getAttribute( 'aria-label' ) );

		$labels = array();
		foreach ( $x->query( '//p[contains(@class, "ab-reveal__label")]/strong' ) as $strong ) {
			$labels[] = trim( $strong->textContent );
		}
		self::assertContains( 'Command for Claude Code', $labels );
		self::assertContains( 'Config for Cursor', $labels );
		self::assertNotContains( 'Config for Cursor / Claude Code', $labels, 'The mcp-remote config does not suit Claude Code.' );

		$note = $x->query( '//div[contains(@class, "ab-reveal")]//p[contains(@class, "ab-note")]' )->item( 0 );
		self::assertNotNull( $note );
		self::assertStringContainsString( 'Claude Code: run the command in your project folder.', $note->textContent );
		self::assertStringContainsString( 'Cursor: paste the config into ~/.cursor/mcp.json.', $note->textContent );
	}

	public function testTheCommandIsTheOneTheGuideShows(): void {
		$js = self::file( 'assets/admin.js' );
		self::assertStringContainsString(
			"setVal( '.ab-reveal-cli', 'claude mcp add --transport http alphabridge ' + endpoint + ' --header \"Authorization: Bearer ' + token + '\"' );",
			$js,
			'claude mcp add --transport http <name> <endpoint> --header "Authorization: Bearer <token>", as on alphabridge-mcp.com.'
		);
		// The ${AUTH} config goes into the Cursor field only.
		self::assertSame( 1, substr_count( $js, 'Authorization:${AUTH}' ) );
		self::assertSame( 1, preg_match( '/var json =.*?Authorization:\$\{AUTH\}.*?setVal\( \'\.ab-reveal-json\', json \);/s', $js ) );
		self::assertStringNotContainsString( "setVal( '.ab-reveal-cli', json", $js );
	}

	public function testTheRateLimitNoteSaysNoMoreThanItIs(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts.' ) );
		$html = $this->call( 'capabilities_card_html', $r->groups(), $r->all() );

		$limit = (int) AB_MCP_Settings::defaults()['rate_limit_per_min'];
		self::assertStringContainsString( 'Abuse protection active: ' . $limit . ' requests/min.', $html );
		self::assertStringNotContainsString( '(fixed)', $html );
	}

	public function testTheReadmesSayWhatTheLogKeeps(): void {
		$keeps = 'latest ' . AB_MCP_Audit_Log::MAX . ' tool calls';
		$limit = (int) AB_MCP_Settings::defaults()['rate_limit_per_min'] . ' requests a minute';
		foreach ( array( 'readme.txt', 'README.md' ) as $name ) {
			$text = (string) preg_replace( '/\s+/', ' ', self::file( $name ) );
			self::assertStringContainsString( $keeps, $text, $name );
			self::assertStringContainsString( $limit, $text, $name );
			self::assertStringNotContainsString( 'records every tool call', $text, $name );
			self::assertStringNotContainsString( 'of every tool call', $text, $name );
			self::assertStringNotContainsString( 'All calls are logged', $text, $name );
			self::assertStringNotContainsString( 'fixed rate limit', $text, $name );
		}
		self::assertStringContainsString( 'The latest ' . AB_MCP_Audit_Log::MAX . ' calls are logged.', self::file( 'readme.txt' ) );
	}
}
