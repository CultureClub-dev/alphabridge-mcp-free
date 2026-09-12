<?php
/**
 * The naming rule from the AlphaBridge site contract, section 5.3.
 *
 * Every tool name carries the CMS prefix, is lower case, and stays under 64
 * characters. The rule is not cosmetic: the hub merges the tool lists of
 * several connected sites by name, and only the prefix keeps a WordPress tool
 * from colliding with a Craft tool that happens to be called the same thing.
 * Two tools merged into one means one of the two sites gets a schema that does
 * not describe it.
 *
 * This test exists because the rule was broken and nobody noticed until the
 * contract suite ran against a live installation: `seo_detect` and `seo_get`
 * shipped without the prefix. Reading the registry by hand had not caught it
 * in three releases.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

final class ToolNamesTest extends TestCase {

	/**
	 * Every name the plugin registers, read from the source.
	 *
	 * Read from the files rather than from a booted registry: the registry
	 * only holds what the current edition and settings allow, and a rule about
	 * names has to cover the names that exist, not the subset that happens to
	 * be switched on.
	 *
	 * @return string[]
	 */
	private function registeredNames(): array {
		$names = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) as $file ) {
			$source = file_get_contents( $file );
			// Deliberately permissive: anything between the quotes. A pattern
			// that only matched [a-z0-9_] would not find a name with a capital
			// letter — and the rule about capitals would then pass because the
			// offending name was never extracted. An assertion that cannot see
			// its own counter-example is not an assertion.
			if ( preg_match_all( '/->register\\(\\s*[\'"]([^\'"]+)[\'"]/', $source, $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		return array_values( array_unique( $names ) );
	}

	public function testThereAreToolsToCheck(): void {
		// Without this the rules below would pass on an empty list, which is
		// how a test about names survives the file layout changing under it.
		$this->assertGreaterThan( 20, count( $this->registeredNames() ) );
	}

	public function testEveryRegistrationYieldedAName(): void {
		// A tool registered through a variable or a constant is invisible to
		// the pattern above, and the rules would then simply not apply to it.
		// Counting the calls and the names catches that: the two must match.
		$calls = 0;
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) as $file ) {
			$calls += preg_match_all( '/->register\\(/', file_get_contents( $file ) );
		}

		$this->assertSame(
			$calls,
			count( $this->registeredNames() ),
			'Some register() call does not pass a literal name, so the naming rules below never see it.'
		);
	}

	public function testEveryToolNameCarriesTheWordPressPrefix(): void {
		foreach ( $this->registeredNames() as $name ) {
			$this->assertStringStartsWith(
				'wp_',
				$name,
				"Tool \"$name\" has no wp_ prefix. The hub merges tool lists across sites by name; without the prefix a WordPress tool collides with a same-named tool from another CMS."
			);
		}
	}

	public function testEveryToolNameIsLowerCaseAndUnder64Characters(): void {
		foreach ( $this->registeredNames() as $name ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z0-9_]{1,63}$/',
				$name,
				"Tool \"$name\" is not [a-z0-9_] under 64 characters."
			);
		}
	}
}
