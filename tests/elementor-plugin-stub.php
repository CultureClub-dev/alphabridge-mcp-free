<?php
/**
 * A stand-in for the one piece of Elementor the reader touches: the plugin
 * singleton with its elements manager, in the shape Elementor 4.3.3 has it
 * (public static $instance, public $elements_manager — includes/plugin.php:56,
 * :106 on plugins.svn.wordpress.org/elementor/tags/4.3.3/). No Elementor code
 * is copied; a test puts its own manager in and takes it out again.
 *
 * @package AlphaBridge_MCP
 */

namespace Elementor;

/**
 * Elementor's plugin singleton, reduced to what the reader reads.
 */
class Plugin {

	/** @var Plugin|null The instance; null until a test loads "Elementor". */
	public static $instance = null;

	/** @var object|null Answers get_element( string $el_type, ?string $widget_type ). */
	public $elements_manager;
}
