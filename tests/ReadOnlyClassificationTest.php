<?php
/**
 * A tool that lists or gets something is read-only.
 *
 * The classification decides three things at once: the `readOnlyHint`
 * annotation a client sees, the global read-only mode, and whether a
 * read-scoped token may run the tool. It works partly by name prefix, which
 * means a rename can change it silently — and silently is the problem. A
 * listing tool reclassified as writing keeps working for full tokens and stops
 * working for read-scoped ones, with a hint that now says the opposite of the
 * truth.
 *
 * This test exists because renaming the WooCommerce tools to `wp_wc_*` did
 * exactly that: the prefix list still said `wc_list_`, so `wp_wc_list_orders`
 * matched nothing and fell through to "not read-only".
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tool_Registry;

final class ReadOnlyClassificationTest extends TestCase {

	/**
	 * Tool names taken from the source, so the rule covers what exists rather
	 * than what the current edition happens to switch on.
	 *
	 * @return string[]
	 */
	private function registeredNames(): array {
		$names = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) as $file ) {
			$source = file_get_contents( $file );
			if ( preg_match_all( '/->register\\(\\s*[\'"]([^\'"]+)[\'"]/', $source, $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		return array_values( array_unique( $names ) );
	}

	public function testAnythingThatListsOrGetsIsReadOnly(): void {
		$checked = 0;
		foreach ( $this->registeredNames() as $name ) {
			if ( ! preg_match( '/_(list|get)_/', $name ) ) {
				continue;
			}
			++$checked;
			$this->assertTrue(
				AB_MCP_Tool_Registry::is_read_only( $name, array() ),
				"\"$name\" lists or gets something but is not classified read-only. Its readOnlyHint would say it writes, and a read-scoped token could not run it."
			);
		}

		// Without this the loop could pass by matching nothing at all.
		$this->assertGreaterThan( 5, $checked );
	}

	public function testAnExplicitFlagStillWins(): void {
		// A tool may force the classification; the name only decides when it
		// does not.
		$this->assertFalse(
			AB_MCP_Tool_Registry::is_read_only( 'wp_list_posts', array( 'readonly' => false ) )
		);
	}

	public function testTheRenamedWooCommerceReadsAreCovered(): void {
		// They live in the Pro add-on, which bundles this core. The
		// classification has to know their new names even though no free
		// installation registers them.
		foreach ( array( 'wp_wc_list_orders', 'wp_wc_get_product', 'wp_wc_list_customers' ) as $name ) {
			$this->assertTrue( AB_MCP_Tool_Registry::is_read_only( $name, array() ), $name );
		}
	}

	public function testWritingToolsAreNotReadOnly(): void {
		foreach ( array( 'wp_wc_update_product', 'wp_wc_create_coupon', 'wp_create_post' ) as $name ) {
			$this->assertFalse( AB_MCP_Tool_Registry::is_read_only( $name, array() ), $name );
		}
	}
}
