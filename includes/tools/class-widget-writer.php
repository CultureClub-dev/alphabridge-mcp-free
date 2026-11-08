<?php
/**
 * The writer of widget settings (Z8, decision 17 of the plan of 01.11.2026;
 * branch 11): a widget instance through the same save-time sanitisation the
 * widgets screen uses. Pulled out of AB_MCP_Tools_Widgets::
 * sanitize_widget_settings() without a change of behaviour, so the widget
 * tools (wp_update_widget, wp_add_widget) and the engine of the takeover
 * (AB_MCP_Sync_Widget_Area_Writer, the kind widget_area) call the one rule:
 * the registered widget's update() and the filter widget_update_callback,
 * in the rights of the account that writes.
 *
 * Writing straight to the widget_<base> option skips
 * WP_Widget::update_callback() — which is where WordPress applies each
 * widget's own update() sanitiser (including the wp_kses_post that the
 * Custom HTML and Text widgets do for users lacking unfiltered_html) and the
 * widget_update_callback filter. Skipping it let a user with only
 * edit_theme_options (e.g. a site administrator on multisite, where
 * unfiltered_html is reserved for super admins) store <script> as a widget
 * value and land a stored XSS that runs in a super admin's session.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Widget_Writer
 */
final class AB_MCP_Widget_Writer {

	/**
	 * The registered widget of a base, or null.
	 *
	 * @param string $base Widget id_base.
	 * @return WP_Widget|null
	 */
	public static function widget( $base ) {
		global $wp_widget_factory;
		if ( $wp_widget_factory && ! empty( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $w ) {
				if ( $w instanceof WP_Widget && isset( $w->id_base ) && $w->id_base === $base ) {
					return $w;
				}
			}
		}
		return null;
	}

	/**
	 * Run a widget instance through the same save-time sanitisation the
	 * widgets screen uses: the registered widget's update() with the full
	 * (already merged) new instance and the old instance, then the core
	 * widget_update_callback filter — the same call and order as
	 * WP_Widget::update_callback(). Nothing is written.
	 *
	 * An unregistered widget type is rejected (fail closed): its update()
	 * cannot be run, and wp_kses_post alone is not a context-aware widget
	 * sanitiser (it would not neutralise a javascript: URL in an
	 * attribute-style field). Writing to such a type could poison the stored
	 * option of a temporarily deactivated widget, which would then render the
	 * data unsanitised on reactivation.
	 *
	 * @param string $base     Widget id_base.
	 * @param int    $number   Instance number, for the widget's _set() context.
	 * @param array  $instance Full new instance (merged, for updates).
	 * @param array  $old      Previous instance, empty for a new widget.
	 * @return array|WP_Error Sanitised instance, or an error if it cannot be saved safely.
	 */
	public static function sanitize( $base, $number, $instance, $old = array() ) {
		$widget = self::widget( $base );

		if ( ! $widget instanceof WP_Widget ) {
			return new WP_Error(
				'ab_mcp_unregistered_widget',
				__( 'This widget type is not currently registered, so its settings cannot be safely sanitised and were not written. Activate the plugin or theme that provides it, then try again.', 'alphabridge-mcp' )
			);
		}

		// Reproduce the widgets screen: set the instance context, run the
		// widget's own update() sanitiser, then the widget_update_callback
		// filter — same call and order as WP_Widget::update_callback().
		$widget->_set( (int) $number );
		try {
			$clean = $widget->update( $instance, (array) $old );
			/** This filter is documented in wp-includes/class-wp-widget.php */
			$clean = apply_filters( 'widget_update_callback', $clean, $instance, (array) $old, $widget );
		} catch ( \Throwable $e ) {
			// The core's sanitizers end with a TypeError on a list where they
			// expect text (sanitize_text_field( array ) under PHP 8).
			return new WP_Error(
				'ab_mcp_widget_rejected',
				sprintf(
					/* translators: %s: class and message of the error */
					__( 'The widget could not sanitise these settings (%s); nothing was saved. Most often a field it keeps as text was sent as a list or an object: wp_get_widgets shows the settings it holds now; send them in that form.', 'alphabridge-mcp' ),
					get_class( $e ) . ': ' . substr( $e->getMessage(), 0, 300 )
				)
			);
		}

		// update() may veto the save by returning false; do NOT write, exactly
		// as WP_Widget::update_callback() skips the save in that case.
		if ( false === $clean ) {
			return new WP_Error(
				'ab_mcp_widget_rejected',
				__( 'The widget rejected these settings; nothing was saved. wp_get_widgets shows the settings it holds now; send them in that form.', 'alphabridge-mcp' )
			);
		}
		return (array) $clean;
	}
}
