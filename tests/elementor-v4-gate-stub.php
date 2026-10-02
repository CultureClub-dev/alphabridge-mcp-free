<?php
/**
 * A stand-in for Elementor's answer to "is the Atomic Editor on?":
 * Mcp_V4_Gate::is_atomic_editor_active() in the shape Elementor 4.3.3 has
 * it (public static, returns bool — modules/mcp/utils/mcp-v4-gate.php:34-36
 * on plugins.svn.wordpress.org/elementor/tags/4.3.3/). No Elementor code is
 * copied. A test sets $active and puts it back to null; null stands for an
 * Elementor that cannot answer (the call fails), which the reader treats
 * like an Elementor before 4.3 without the class.
 *
 * @package AlphaBridge_MCP
 */

namespace Elementor\Modules\Mcp\Utils;

/**
 * Elementor's V4 gate, reduced to the one question the reader asks.
 */
class Mcp_V4_Gate {

	/** @var bool|null Whether the Atomic Editor is on; null: no answer. */
	public static $active = null;

	/**
	 * Whether the Atomic Editor is on.
	 *
	 * @return bool
	 * @throws \RuntimeException When the test says Elementor cannot answer.
	 */
	public static function is_atomic_editor_active(): bool {
		if ( null === self::$active ) {
			throw new \RuntimeException( 'Elementor cannot answer here.' );
		}
		return self::$active;
	}
}
