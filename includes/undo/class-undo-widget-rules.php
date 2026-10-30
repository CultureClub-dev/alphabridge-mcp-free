<?php
/**
 * The rules of the widget tools, for the undo of their changes.
 *
 * A restore must never store what the tool itself would not (see
 * AB_MCP_Undo_Widgets::refusal()): the settings of a widget pass the
 * widget's own update() there, and a value wp_get_widgets leaves out as a
 * credential stays out of every answer. The rules live in the tool class as
 * protected methods — AlphaBridge MCP Pro declares the same ones in its
 * subclass — so this class inherits them instead of keeping a copy that
 * could drift apart, as AB_MCP_Undo_Meta_Rules does for the meta tools.
 * Abstract: it is never registered and never runs a tool.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/tools/class-tools-widgets.php';

/**
 * Class AB_MCP_Undo_Widget_Rules
 */
abstract class AB_MCP_Undo_Widget_Rules extends AB_MCP_Tools_Widgets {

	/**
	 * The settings of one widget as its own update() and the
	 * widget_update_callback filter store them (sanitize_widget_settings()).
	 *
	 * @param string $base     Widget id_base.
	 * @param int    $number   Instance number.
	 * @param array  $instance Settings to store.
	 * @param array  $old      Settings stored now, empty for none.
	 * @return array|WP_Error
	 */
	public static function sanitize_instance( $base, $number, array $instance, array $old ) {
		return self::sanitize_widget_settings( (string) $base, (int) $number, $instance, $old );
	}

	/**
	 * Does wp_get_widgets leave a setting under this key out?
	 *
	 * @param string $key Settings key.
	 * @return bool
	 */
	public static function key_hidden( $key ) {
		return self::is_sensitive_meta_key( (string) $key );
	}
}
