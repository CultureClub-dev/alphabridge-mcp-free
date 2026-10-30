<?php
/**
 * Widget and sidebar tools (classic-widget API). Sidebars come from
 * $wp_registered_sidebars; widget instances live in the widget_{id_base}
 * options and their placement in wp_get_sidebars_widgets().
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';
require_once dirname( __DIR__ ) . '/undo/class-undo-widgets.php';

/**
 * Class AB_MCP_Tools_Widgets
 */
class AB_MCP_Tools_Widgets extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_list_sidebars',
			array(
				'description' => "List the widget areas (sidebars) the active theme registers: [{id, name, description, widget_count}], followed by an entry wp_inactive_widgets for widgets placed in no area. A theme that registers no widget areas, as most block themes, yields only that entry. Needs edit_theme_options. Read-only; takes no arguments. The widgets in an area and their settings come from wp_get_widgets.",
				'capability'  => 'edit_theme_options',
				'callback'    => array( __CLASS__, 'list_sidebars' ),
			)
		);

		$r->register(
			'wp_get_widgets',
			array(
				'description' => 'List the site\'s widgets with their settings: by default those of all widget areas, inactive widgets included; with sidebar, only those of one area. Returns widgets: [{id, id_base, number, name, sidebar, settings}]; id is e.g. "text-2", sidebar the area id (empty when the widget is in none). A setting whose key looks like a credential (an API key, token, secret or password) is left out and named in hidden_keys. Needs edit_theme_options. Read-only; wp_list_sidebars lists the areas themselves.',
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'sidebar' => array( 'type' => 'string', 'description' => 'Optional widget area id from wp_list_sidebars, e.g. "sidebar-1" or "wp_inactive_widgets". When omitted, widgets of all areas are listed.' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_widgets' ),
			)
		);

		// The three writing tools came with AlphaBridge MCP Pro only until
		// 4.9.0. Pro registers its own copy of these names over them; the
		// definitions here are Pro's, without its tier.
		$r->register(
			'wp_update_widget',
			array(
				'description' => 'Update the settings of an existing widget instance (merges the given fields at the top level; a nested value sent replaces the stored one). The merged instance passes the widget\'s own update() sanitisation and the widget_update_callback filter, the same as the widgets screen — so HTML is filtered unless the account may post unfiltered HTML. A value that wp_get_widgets leaves out as a credential stays as stored when it is left out, also inside nested settings, and the answer leaves it out too; one it shows as [REDACTED] stays as stored when it is left out or sent back as [REDACTED]. Returns updated, id, the stored settings, saved_fields (the fields sent, as stored afterwards and read again; a credential is compared, not shown, and saved_differs and warning name a field the widget stored otherwise than sent) and undo: the token for wp_undo, which puts the widget type\'s option row back byte for byte, or null with undo_note saying why. An unknown id, an unregistered widget type or a value in a shape the widget does not keep is refused, and nothing is saved.',
				'open_world'  => true, // a widget in an active area is public.
				'capability'  => 'edit_theme_options',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id', 'settings' ),
					'properties' => array(
						'id'       => array( 'type' => 'string', 'description' => 'Widget id "<id_base>-<number>" as wp_get_widgets lists it, e.g. "text-2".' ),
						'settings' => array( 'type' => 'object', 'description' => 'Fields to merge into the instance, e.g. {"title":"…","text":"…"}. Values: text, numbers, true, false, null, or lists nested no deeper than the widget already keeps that field.' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_widget' ),
			)
		);

		$r->register(
			'wp_add_widget',
			array(
				'description' => 'Add a new widget of a registered type to a sidebar, or to wp_inactive_widgets. Returns added, id (the new "<id_base>-<number>"), sidebar, saved_fields (as for wp_update_widget) and undo: the token for wp_undo, which puts the widget type\'s option row and the widgets per sidebar back as they were, or null with undo_note saying why. Settings pass the widget\'s own update() sanitisation and the widget_update_callback filter, the same as the widgets screen — so HTML is filtered unless the account may post unfiltered HTML. An unknown type or sidebar, or settings the widget rejects, are refused and nothing is saved. Existing widgets are changed by wp_update_widget.',
				'destructive' => true, // what is published cannot be taken back (PUBLISHING in the registry).
				'open_world'  => true, // a widget in an active area is public at once.
				'capability'  => 'edit_theme_options',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id_base', 'sidebar' ),
					'properties' => array(
						'id_base'  => array( 'type' => 'string', 'description' => 'Widget type base, e.g. "text", "nav_menu", "block", "search". Must be a registered type; the refusal lists the available ones.' ),
						'sidebar'  => array( 'type' => 'string', 'description' => 'Target sidebar id as wp_list_sidebars lists it, e.g. "sidebar-1", or "wp_inactive_widgets".' ),
						'settings' => array( 'type' => 'object', 'description' => 'Initial settings for the new instance: text, numbers, true, false, null or flat lists. Optional; omitted means no settings.' ),
						'position' => array( 'type' => 'integer', 'description' => 'Optional 0-based insert position within the sidebar; appended by default. Negative values count as 0.' ),
					),
				),
				'callback'    => array( __CLASS__, 'add_widget' ),
			)
		);

		$r->register(
			'wp_delete_widget',
			array(
				'description' => 'Remove a widget instance from its sidebar and delete its settings. The widget option is rewritten directly, without the widget\'s own cleanup routines. Returns deleted, id and undo: the token for wp_undo, which puts the widget type\'s option row and the widgets per sidebar back as they were, or null with undo_note saying why; an id found neither in a sidebar nor in the stored settings is reported as not found. The settings of an existing widget change with wp_update_widget.',
				'capability'  => 'edit_theme_options',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array( 'type' => 'string', 'description' => 'Widget id "<id_base>-<number>" as wp_get_widgets lists it, e.g. "text-2".' ),
					),
				),
				'callback'    => array( __CLASS__, 'delete_widget' ),
			)
		);
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * Sidebar => widget-ids map. Reads the raw option instead of the private
	 * core function wp_get_sidebars_widgets() (flagged by Plugin Check).
	 *
	 * @return array<string,mixed>
	 */
	protected static function sidebars_map() {
		$map = get_option( 'sidebars_widgets', array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Split a widget id "base-number" into its parts.
	 *
	 * @param string $id Widget id.
	 * @return array{0:string,1:int} [ id_base, number ]
	 */
	protected static function split_id( $id ) {
		// \z, not $: $ also matches before a trailing newline, so "text-2\n" was
		// split into text/2 while the sidebar map holds "text-2" — the option
		// row went, the sidebar entry stayed behind as an orphan. Surrounding
		// whitespace is trimmed first; the widgets screen never sends any.
		if ( ! is_scalar( $id ) ) {
			return array( '', 0 );
		}
		$id = trim( (string) $id );
		if ( preg_match( '/^(.+)-(\d+)\z/', $id, $m ) ) {
			return array( $m[1], (int) $m[2] );
		}
		return array( '', 0 );
	}

	/**
	 * A widget's settings as wp_get_widgets shows them: every value whose key
	 * looks like a credential (is_sensitive_meta_key(), the guard of the meta
	 * tools) is left out, at any depth, and its path goes to $hidden.
	 *
	 * Widgets of other plugins keep API keys and tokens in their settings (a
	 * feed widget its consumer secret, for example), and this reader runs in
	 * the mode Read and on a read token. Left out rather than replaced by a
	 * marker: a marker sent back in an update would overwrite the stored
	 * value, a missing key leaves it as it is.
	 *
	 * @param mixed    $settings The stored settings of one widget, or a part of them.
	 * @param string[] $hidden   Paths of the values left out, e.g. "api_key" or "feed/secret".
	 * @param string   $path     Path of $settings itself, '' at the top.
	 * @return mixed
	 */
	protected static function without_credentials( $settings, array &$hidden, $path = '' ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		$out = array();
		foreach ( $settings as $key => $value ) {
			$at = '' === $path ? (string) $key : $path . '/' . $key;
			if ( self::is_sensitive_meta_key( (string) $key ) ) {
				$hidden[] = $at;
				continue;
			}
			$out[ $key ] = self::without_credentials( $value, $hidden, $at );
		}
		return $out;
	}

	/* --------------------------------------------------------------- reads */

	/**
	 * List registered sidebars.
	 *
	 * @return array
	 */
	public static function list_sidebars() {
		global $wp_registered_sidebars;
		$map = self::sidebars_map();
		$out = array();
		if ( is_array( $wp_registered_sidebars ) ) {
			foreach ( $wp_registered_sidebars as $id => $sb ) {
				$out[] = array(
					'id'           => $id,
					'name'         => isset( $sb['name'] ) ? $sb['name'] : $id,
					'description'  => isset( $sb['description'] ) ? $sb['description'] : '',
					'widget_count' => isset( $map[ $id ] ) && is_array( $map[ $id ] ) ? count( $map[ $id ] ) : 0,
				);
			}
		}
		$inactive = isset( $map['wp_inactive_widgets'] ) && is_array( $map['wp_inactive_widgets'] ) ? count( $map['wp_inactive_widgets'] ) : 0;
		$out[]    = array(
			'id'           => 'wp_inactive_widgets',
			'name'         => __( 'Inactive widgets', 'alphabridge-mcp' ),
			'description'  => __( 'Widgets not placed in any active sidebar.', 'alphabridge-mcp' ),
			'widget_count' => $inactive,
		);
		return array( 'sidebars' => $out );
	}

	/**
	 * List widgets, optionally within one sidebar.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function get_widgets( $a ) {
		global $wp_registered_widgets;
		$want = self::s( $a, 'sidebar', '' );
		$map  = self::sidebars_map();
		$loc  = array();
		foreach ( $map as $sb => $ids ) {
			if ( 'array_version' === $sb || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $wid ) {
				$loc[ $wid ] = $sb;
			}
		}
		$out    = array();
		$hidden = false;
		$ids    = is_array( $wp_registered_widgets ) ? array_keys( $wp_registered_widgets ) : array();
		foreach ( $ids as $wid ) {
			$sidebar = isset( $loc[ $wid ] ) ? $loc[ $wid ] : '';
			if ( '' !== $want && $sidebar !== $want ) {
				continue;
			}
			list( $base, $num ) = self::split_id( $wid );
			$settings = null;
			if ( '' !== $base ) {
				$opt = get_option( 'widget_' . $base );
				if ( is_array( $opt ) && isset( $opt[ $num ] ) ) {
					$settings = $opt[ $num ];
				}
			}
			$info  = $wp_registered_widgets[ $wid ];
			$left  = array();
			$entry = array(
				'id'       => $wid,
				'id_base'  => $base,
				'number'   => $num,
				'name'     => isset( $info['name'] ) ? $info['name'] : $wid,
				'sidebar'  => $sidebar,
				'settings' => self::without_credentials( $settings, $left ),
			);
			if ( $left ) {
				$entry['hidden_keys'] = $left;
				$hidden               = true;
			}
			$out[] = $entry;
		}
		$res = array( 'widgets' => $out );
		if ( $hidden ) {
			$res['note'] = __( 'Settings whose key looks like a credential (an API key, token, secret or password) are not shown; hidden_keys names them per widget. They stay stored unchanged and are managed in the widget\'s own form in wp-admin under Appearance › Widgets.', 'alphabridge-mcp' );
		}
		return $res;
	}

	/* --------------------------------------------------------------- writes */

	/*
	 * The writing tools are AlphaBridge MCP Pro's code, free since 4.9.0.
	 * AB_MCP_Tools_Widgets_Pro of every Pro release since 4.5.3 extends this
	 * class and declares update_widget(), add_widget(), delete_widget() and
	 * sanitize_widget_settings() itself, with the same signatures as here; the
	 * helpers are private, so a Pro release that declares them differently or
	 * not at all stays compatible.
	 */

	/**
	 * Run a widget instance through the same save-time sanitisation the widgets
	 * screen uses. These tools write straight to the widget_<base> option and so
	 * skip WP_Widget::update_callback() — which is where WordPress applies each
	 * widget's own update() sanitiser (including the wp_kses_post that the Custom
	 * HTML and Text widgets do for users lacking unfiltered_html) and the
	 * widget_update_callback filter. Skipping it let a user with only
	 * edit_theme_options (e.g. a site administrator on multisite, where
	 * unfiltered_html is reserved for super admins) store <script> as a widget
	 * value and land a stored XSS that runs in a super admin's session.
	 *
	 * The registered widget's update() is called with the full (already merged)
	 * new instance and the old instance, then the core widget_update_callback
	 * filter is applied — the same call and order as WP_Widget::update_callback().
	 *
	 * An unregistered widget type is rejected (fail closed): its update() cannot
	 * be run, and wp_kses_post alone is not a context-aware widget sanitiser
	 * (it would not neutralise a javascript: URL in an attribute-style field).
	 * Writing to such a type could poison the stored option of a temporarily
	 * deactivated widget, which would then render the data unsanitised on
	 * reactivation.
	 *
	 * @param string $base     Widget id_base.
	 * @param int    $number   Instance number, for the widget's _set() context.
	 * @param array  $instance Full new instance (merged, for updates).
	 * @param array  $old      Previous instance, empty for a new widget.
	 * @return array|WP_Error Sanitised instance, or an error if it cannot be saved safely.
	 */
	protected static function sanitize_widget_settings( $base, $number, $instance, $old = array() ) {
		global $wp_widget_factory;
		$widget = null;
		if ( $wp_widget_factory && ! empty( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $w ) {
				if ( $w instanceof WP_Widget && isset( $w->id_base ) && $w->id_base === $base ) {
					$widget = $w;
					break;
				}
			}
		}

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

	/**
	 * Set of registered widget id_bases (e.g. text, nav_menu, block …).
	 *
	 * @return array<string,bool>
	 */
	private static function valid_bases() {
		global $wp_widget_factory;
		$bases = array();
		if ( $wp_widget_factory && ! empty( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $w ) {
				if ( isset( $w->id_base ) && '' !== $w->id_base ) {
					$bases[ $w->id_base ] = true;
				}
			}
		}
		return $bases;
	}

	/**
	 * Settings in the shape a widget keeps them. The widgets screen sends
	 * text, and lists from a field group; settings over MCP may hold any
	 * JSON. The core's sanitizers end with a TypeError on a list where they
	 * expect text, and a widget whose update() hands its input back unchecked
	 * stores whatever came, which its output may then not print (esc_html()
	 * of a list ends the page with a TypeError under PHP 8). So a value is
	 * text, a number, true, false or null, or a list of such values; nested
	 * lists only as deep as the widget keeps that field already (a repeater
	 * of a widgets bundle), and no list where it keeps text.
	 *
	 * @param array $sent The settings sent.
	 * @param array $old  The stored instance, empty for a new widget.
	 * @return WP_Error|null
	 */
	private static function shape_refusal( array $sent, array $old ) {
		foreach ( $sent as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				continue;
			}
			$stored = array_key_exists( $key, $old ) ? $old[ $key ] : null;
			// An empty text is text too: a title left empty is the common case.
			if ( is_string( $stored ) || is_int( $stored ) || is_float( $stored ) ) {
				return new WP_Error(
					'ab_mcp_widget_settings_shape',
					sprintf(
						/* translators: %s: settings key */
						__( 'settings.%s: the widget keeps a text there, and a list or an object was sent. Send it as text, as wp_get_widgets shows it; nothing was saved.', 'alphabridge-mcp' ),
						(string) $key
					)
				);
			}
			$most = max( 1, self::depth( $stored ) );
			if ( ! is_array( $value ) || self::depth( $value ) > $most ) {
				return new WP_Error(
					'ab_mcp_widget_settings_shape',
					sprintf(
						/* translators: 1: settings key, 2: levels */
						__( 'settings.%1$s is nested deeper than the widget keeps it (lists go at most %2$d deep here, with text, numbers, true, false or null at the bottom). The widgets screen sends no deeper data for this field; nothing was saved. wp_get_widgets shows the settings it holds now.', 'alphabridge-mcp' ),
						(string) $key,
						$most
					)
				);
			}
		}
		return null;
	}

	/**
	 * Levels of lists in a value: 0 for text or a number, 1 for a list of
	 * them; anything that is neither counts as too deep.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function depth( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return 0;
		}
		if ( ! is_array( $value ) ) {
			return PHP_INT_MAX;
		}
		$deepest = 0;
		foreach ( $value as $v ) {
			$d = self::depth( $v );
			if ( PHP_INT_MAX === $d ) {
				return PHP_INT_MAX;
			}
			$deepest = max( $deepest, $d );
		}
		return $deepest + 1;
	}

	/**
	 * Store the sidebar map the way wp_set_sidebars_widgets() does, minus the
	 * filtered read that function pairs it with.
	 *
	 * wp_get_sidebars_widgets() runs the `sidebars_widgets` filter and, outside
	 * wp-admin, serves an in-memory copy — and a REST request is not wp-admin.
	 * A plugin that hides widgets by context (visibility rules, languages)
	 * filters them out of that view; writing the filtered map back dropped
	 * them from their sidebars for good. So the map is read as stored
	 * (sidebars_map()), as the widgets screen edits it, and written here.
	 *
	 * Whether it stored, the callers ask for their own widget
	 * (sidebars_holding()).
	 *
	 * @param array $map Sidebar map.
	 */
	private static function store_sidebar_map( array $map ) {
		global $_wp_sidebars_widgets;
		// The copy wp_get_sidebars_widgets() keeps for the request: cleared
		// before the write, as wp_set_sidebars_widgets() clears it, so a core
		// reader or an update hook later in the same request sees the new map.
		$_wp_sidebars_widgets = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the request copy of the core, cleared as wp_set_sidebars_widgets() clears it.
		if ( ! isset( $map['array_version'] ) ) {
			$map['array_version'] = 3;
		}
		update_option( 'sidebars_widgets', $map );
	}

	/**
	 * The sidebars the stored map names this widget in. Asked after a write
	 * of the map, which update_option() does not confirm and a filter can
	 * turn down or change elsewhere: what counts is where this one widget is.
	 *
	 * @param string $id Widget id.
	 * @return string[] Sidebar ids.
	 */
	private static function sidebars_holding( $id ) {
		$in = array();
		foreach ( self::sidebars_map() as $sb => $ids ) {
			if ( 'array_version' !== $sb && is_array( $ids ) && in_array( $id, $ids, true ) ) {
				$in[] = (string) $sb;
			}
		}
		return $in;
	}

	/**
	 * May this widget's option row be written at all?
	 *
	 * The row is "widget_{base}" and the base comes from the CALLER, so this
	 * is a caller-chosen option name and has to face the same question every
	 * other option writer faces. Without it "wp_delete_widget" with the base
	 * "token" reached widget_token, which the credential needle protects, and
	 * the read-back said nothing about that — it proves a write happened, not
	 * that it was allowed.
	 *
	 * The question is answered by the option guard of AlphaBridge MCP Pro,
	 * where the option tools are. Where it is not loaded, a row that is there
	 * is touched only when the widgets of WordPress wrote it: WP_Widget marks
	 * every row it saves with _multiwidget (WP_Widget::save_settings()), and a
	 * row of another plugin that happens to be called widget_<something>
	 * carries no such mark. Writing and adding still need a registered widget
	 * type of exactly that base (sanitize_widget_settings()).
	 *
	 * @param string $base Widget base id.
	 * @return WP_Error|false
	 */
	private static function widget_option_refused( $base ) {
		// The rule lives with the undo of these tools since 4.13.0, so a
		// restore asks the very same (AB_MCP_Undo_Widgets::refusal()).
		return AB_MCP_Undo_Widgets::option_refusal( $base );
	}

	/**
	 * Settings sent for an update, with the credential values wp_get_widgets
	 * left out or showed as [REDACTED] put back from the stored instance where
	 * they are missing inside nested settings: every key the reader redacts
	 * (AB_MCP_Redactor::redacts_key(), which holds the rule of
	 * is_sensitive_meta_key()). The update merges the top level only, where
	 * array_merge keeps a key that was not sent; a nested array sent back as
	 * it was read would replace the stored one without them.
	 *
	 * @param array      $new    Settings sent, or a nested part of them.
	 * @param array      $old    The stored counterpart.
	 * @param bool       $nested Whether $new is below the top level.
	 * @param array|null $extra  AB_MCP_Redactor::extra_keys(); null to ask the filter.
	 * @return array
	 */
	private static function keep_hidden_credentials( array $new, array $old, $nested = false, ?array $extra = null ) {
		$extra = null === $extra ? AB_MCP_Redactor::extra_keys() : $extra;
		if ( $nested ) {
			foreach ( $old as $key => $value ) {
				if ( ! array_key_exists( $key, $new ) && AB_MCP_Redactor::redacts_key( $key, $extra ) ) {
					$new[ $key ] = $value;
				}
			}
		}
		foreach ( $new as $key => $value ) {
			if ( is_array( $value ) && isset( $old[ $key ] ) && is_array( $old[ $key ] ) ) {
				$new[ $key ] = self::keep_hidden_credentials( $value, $old[ $key ], true, $extra );
			}
		}
		return $new;
	}

	/**
	 * Did the widget row really land in the option?
	 *
	 * update_option() cannot tell "failed" from "already that value", so the
	 * option is read back and the ONE instance that was written is compared —
	 * not the whole array, which another process may legitimately have added
	 * to in the meantime.
	 *
	 * @param string $base     Widget base id.
	 * @param int    $num      Instance number.
	 * @param mixed  $expected Settings that should now be stored, or null when
	 *                         the instance should be gone.
	 * @return bool
	 */
	private static function widget_row_holds( $base, $num, $expected ) {
		$now = get_option( 'widget_' . $base );
		if ( null === $expected ) {
			return ! is_array( $now ) || ! isset( $now[ $num ] );
		}
		return is_array( $now ) && isset( $now[ $num ] ) && $now[ $num ] == $expected; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- WordPress stores scalars as strings, so an int written comes back as a string; the structure is what must match.
	}

	/**
	 * Update an existing widget instance.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_widget( $a ) {
		$id                 = trim( self::s( $a, 'id' ) );
		list( $base, $num ) = self::split_id( $id );
		if ( '' === $base ) {
			return new WP_Error( 'ab_mcp_bad_widget', __( 'Invalid widget id (expected "base-number", e.g. "text-2"). wp_get_widgets lists the widgets and their ids.', 'alphabridge-mcp' ) );
		}
		$opt = get_option( 'widget_' . $base );
		if ( ! is_array( $opt ) || ! isset( $opt[ $num ] ) ) {
			return new WP_Error( 'ab_mcp_no_widget', __( 'Widget instance not found. wp_get_widgets lists the widgets and their ids.', 'alphabridge-mcp' ) );
		}
		$old   = (array) $opt[ $num ];
		$sent  = self::arr( $a, 'settings' );
		$shape = self::shape_refusal( $sent, $old );
		if ( null !== $shape ) {
			return $shape;
		}
		// A value read with [REDACTED] in it gets the stored one of this
		// instance back, or is refused (since 4.13.0); saved_fields compares
		// the restored settings.
		$sent = AB_MCP_Redactor::restore_placeholders( $sent, $old, $left );
		if ( $left ) {
			return AB_MCP_Redactor::refusal( '/settings' );
		}
		$merged = array_merge( $old, self::keep_hidden_credentials( $sent, $old ) );
		$clean  = self::sanitize_widget_settings( $base, $num, $merged, $old );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$refused = self::widget_option_refused( $base );
		if ( $refused ) {
			return $refused;
		}
		// The row as stored, right before the write (since 4.13.0).
		$snap                = AB_MCP_Undo_Widgets::take( $base, array( 'widget_' . $base ) );
		$opt[ $num ]         = $clean;
		$opt['_multiwidget'] = 1;
		update_option( 'widget_' . $base, $opt );
		// PROVEN, not assumed: a filter or a failing write must not come back
		// as a success.
		if ( ! self::widget_row_holds( $base, $num, $clean ) ) {
			return new WP_Error( 'ab_mcp_write_failed', __( 'The widget settings were not stored — a filter or the database rejected the write. Use Appearance › Widgets in wp-admin to change the widget.', 'alphabridge-mcp' ) );
		}
		$undo = AB_MCP_Undo_Widgets::answer(
			array(
				'tool'    => 'wp_update_widget',
				'snap'    => $snap,
				'summary' => 'widget ' . $id,
				'asked'   => $sent,
				'saved'   => AB_MCP_Undo_Widgets::instance( $base, $num ),
			)
		);
		// The answer shows the settings as wp_get_widgets does: a write tool
		// must not read out what the reader leaves out.
		$left = array();
		$res  = array(
			'updated'  => true,
			'id'       => $id,
			'settings' => self::without_credentials( $opt[ $num ], $left ),
		);
		if ( $left ) {
			$res['hidden_keys'] = $left;
		}
		return $res + $undo;
	}

	/**
	 * Add a new widget instance to a sidebar.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function add_widget( $a ) {
		$base    = self::s( $a, 'id_base' );
		$sidebar = self::s( $a, 'sidebar' );
		if ( '' === $base ) {
			return new WP_Error( 'ab_mcp_bad_base', __( 'A widget id_base is required. Pass the widget type, e.g. "text" or "search".', 'alphabridge-mcp' ) );
		}
		$bases = self::valid_bases();
		if ( ! empty( $bases ) && ! isset( $bases[ $base ] ) ) {
			return new WP_Error(
				'ab_mcp_unknown_base',
				sprintf(
					/* translators: 1: requested base, 2: comma-separated list of valid bases */
					__( 'Unknown widget type "%1$s". Use one of the available types: %2$s', 'alphabridge-mcp' ),
					$base,
					implode( ', ', array_keys( $bases ) )
				)
			);
		}
		global $wp_registered_sidebars;
		$valid = ( is_array( $wp_registered_sidebars ) && isset( $wp_registered_sidebars[ $sidebar ] ) ) || 'wp_inactive_widgets' === $sidebar;
		if ( ! $valid ) {
			return new WP_Error( 'ab_mcp_no_sidebar', __( 'Target sidebar not found. wp_list_sidebars lists the sidebars.', 'alphabridge-mcp' ) );
		}

		$opt = get_option( 'widget_' . $base );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		$numbers = array_filter( array_keys( $opt ), 'is_int' );
		$num     = $numbers ? ( max( $numbers ) + 1 ) : 2;
		$shape   = self::shape_refusal( self::arr( $a, 'settings' ), array() );
		if ( null !== $shape ) {
			return $shape;
		}
		$clean = self::sanitize_widget_settings( $base, $num, self::arr( $a, 'settings' ), array() );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$refused = self::widget_option_refused( $base );
		if ( $refused ) {
			return $refused;
		}
		// Both rows as stored, right before the first write (since 4.13.0).
		$snap                = AB_MCP_Undo_Widgets::take( $base, array( 'widget_' . $base, AB_MCP_Undo_Widgets::MAP ) );
		$opt[ $num ]         = $clean;
		$opt['_multiwidget'] = 1;
		update_option( 'widget_' . $base, $opt );
		// Checked BEFORE the sidebar map is touched: a widget id in a sidebar
		// whose settings row never stored is a broken sidebar entry.
		if ( ! self::widget_row_holds( $base, $num, $clean ) ) {
			return new WP_Error( 'ab_mcp_write_failed', __( 'The widget settings were not stored, so it was not added to the sidebar. Use Appearance › Widgets in wp-admin to add it.', 'alphabridge-mcp' ) );
		}

		$wid = $base . '-' . $num;
		$map = self::sidebars_map();
		if ( ! isset( $map[ $sidebar ] ) || ! is_array( $map[ $sidebar ] ) ) {
			$map[ $sidebar ] = array();
		}
		$pos = isset( $a['position'] ) ? max( 0, (int) $a['position'] ) : count( $map[ $sidebar ] );
		array_splice( $map[ $sidebar ], $pos, 0, $wid );
		self::store_sidebar_map( $map );
		$held = self::sidebars_holding( $wid );
		if ( ! in_array( (string) $sidebar, $held, true ) ) {
			if ( array() !== $held ) {
				// Placed, but elsewhere: its settings stay with it.
				return new WP_Error(
					'ab_mcp_write_failed',
					sprintf(
						/* translators: %s: the sidebars that hold the widget */
						__( 'The widget was stored, but not in the sidebar asked for: a filter put it in %s. Use Appearance › Widgets in wp-admin to move it.', 'alphabridge-mcp' ),
						implode( ', ', $held )
					)
				);
			}
			// Its settings without a place in a sidebar would be a widget
			// nobody sees and nobody lists: they are taken back — read
			// afresh, so a change to another widget since stays.
			$now = get_option( 'widget_' . $base );
			if ( is_array( $now ) ) {
				unset( $now[ $num ] );
				update_option( 'widget_' . $base, $now );
			}
			return new WP_Error( 'ab_mcp_write_failed', __( 'The widget was not added: the list of widgets per sidebar was not stored (a filter or the database rejected the write), and its settings were taken back. Use Appearance › Widgets in wp-admin to add it.', 'alphabridge-mcp' ) );
		}

		return array(
			'added'   => true,
			'id'      => $wid,
			'sidebar' => $sidebar,
		) + AB_MCP_Undo_Widgets::answer(
			array(
				'tool'    => 'wp_add_widget',
				'snap'    => $snap,
				'summary' => sprintf( 'new widget %s in %s', $wid, $sidebar ),
				'asked'   => self::arr( $a, 'settings' ),
				'saved'   => AB_MCP_Undo_Widgets::instance( $base, $num ),
			)
		);
	}

	/**
	 * Delete a widget instance.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_widget( $a ) {
		$id                 = trim( self::s( $a, 'id' ) );
		list( $base, $num ) = self::split_id( $id );
		if ( '' === $base ) {
			return new WP_Error( 'ab_mcp_bad_widget', __( 'Invalid widget id (expected "base-number", e.g. "text-2"). wp_get_widgets lists the widgets and their ids.', 'alphabridge-mcp' ) );
		}
		// Asked before anything is touched: removing the sidebar entry first
		// and refusing the option row afterwards would leave half a deletion.
		$refused = self::widget_option_refused( $base );
		if ( $refused ) {
			return $refused;
		}
		// Both rows as stored, right before the first write (since 4.13.0).
		$snap    = AB_MCP_Undo_Widgets::take( $base, array( 'widget_' . $base, AB_MCP_Undo_Widgets::MAP ) );
		$map     = self::sidebars_map();
		$removed = false;
		foreach ( $map as $sb => $ids ) {
			if ( 'array_version' === $sb || ! is_array( $ids ) ) {
				continue;
			}
			$i = array_search( $id, $ids, true );
			if ( false !== $i ) {
				unset( $map[ $sb ][ $i ] );
				$map[ $sb ] = array_values( $map[ $sb ] );
				$removed    = true;
			}
		}
		// Written only when something left the map — a write that changes
		// nothing still persists whatever the read returned.
		if ( $removed ) {
			self::store_sidebar_map( $map );
		}
		if ( $removed && array() !== self::sidebars_holding( $id ) ) {
			// Its settings stay: a sidebar entry without them would be broken.
			return new WP_Error( 'ab_mcp_write_failed', __( 'The widget was not removed: the list of widgets per sidebar was not stored (a filter or the database rejected the write); its settings were left as they are. Use Appearance › Widgets in wp-admin to remove it.', 'alphabridge-mcp' ) );
		}

		$opt = get_option( 'widget_' . $base );
		if ( is_array( $opt ) && isset( $opt[ $num ] ) ) {
			unset( $opt[ $num ] );
			update_option( 'widget_' . $base, $opt );
			if ( ! self::widget_row_holds( $base, $num, null ) ) {
				return new WP_Error( 'ab_mcp_write_failed', __( 'The widget settings are still stored — a filter or the database prevented the removal. Use Appearance › Widgets in wp-admin to remove the widget.', 'alphabridge-mcp' ) );
			}
			$removed = true;
		}
		if ( ! $removed ) {
			return new WP_Error( 'ab_mcp_no_widget', __( 'Widget not found. wp_get_widgets lists the widgets and their ids.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
		) + AB_MCP_Undo_Widgets::answer(
			array(
				'tool'    => 'wp_delete_widget',
				'snap'    => $snap,
				'summary' => 'deleted widget ' . $id,
			)
		);
	}
}
