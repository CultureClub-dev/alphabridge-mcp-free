<?php
/**
 * Undo kind widget (since 4.13.0): the option rows wp_update_widget,
 * wp_add_widget and wp_delete_widget write — widget_<id_base>, where the
 * settings of every widget of a type live, and for adding and deleting
 * sidebars_widgets, the widgets per sidebar — taken before the write and
 * put back byte for byte.
 *
 * Snapshot (one point, one widget type):
 *   { "v": 1, "base": string,
 *     "rows":  { "<option>": { "existed": false }
 *                          | { "existed": true, "value"|"b64": …, "autoload": string } },
 *     "after": { "option:<option>": sha256 } }
 * As with the meta (AB_MCP_Undo_Meta): the raw bytes of the row, not
 * get_option() — that would unserialize, and update_option() serialize
 * again —, written back with the row's id and flag, the caches of the
 * options dropped; a row the change created deleted again. The whole row
 * counts: a change since to another widget of the same type, or to the
 * placement of any widget, is a change since, refused unless force is set.
 *
 * The restore asks again what the tools ask: the right of the tool
 * (edit_theme_options, by AB_MCP_Undo_Restore::may_touch()), whether the
 * row of this widget type may be written at all (option_refusal(), the rule
 * of the tools), and the row the database holds under exactly this name. An
 * account without unfiltered_html gets no settings back the widget's own
 * update() would not store for it (the widgets screen runs it, and so do the
 * tools): each setting that changes is run through it and has to come out
 * as it is. A credential the widget keeps (a value wp_get_widgets leaves
 * out) is in the snapshot, as it must be to be put back, and in no answer:
 * wp_undo_list, a dry run and a refusal name option names and widget ids.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-undo-objects.php';

/**
 * Class AB_MCP_Undo_Widgets
 */
class AB_MCP_Undo_Widgets extends AB_MCP_Undo_Objects {

	/** The undo kind. */
	const KIND = 'widget';

	/** The row of the widgets per sidebar. */
	const MAP = 'sidebars_widgets';

	/** The tools that record this kind, and whether they write the map too. */
	const TOOLS = array(
		'wp_update_widget' => false,
		'wp_add_widget'    => true,
		'wp_delete_widget' => true,
	);

	/* ------------------------------------------------------ the tools' rule */

	/**
	 * May the row of this widget type be written at all? The rule of the
	 * widget tools, kept here so their restore asks the very same
	 * (AB_MCP_Tools_Widgets::widget_option_refused() hands on to it): the
	 * option guard of AlphaBridge MCP Pro where it is loaded, else a row
	 * that is there only when the widgets of WordPress wrote it (it carries
	 * _multiwidget, WP_Widget::save_settings()).
	 *
	 * @param string $base Widget base id.
	 * @return WP_Error|false
	 */
	public static function option_refusal( $base ) {
		if ( class_exists( 'AB_MCP_Tools_Options_Settings_Pro' ) && method_exists( 'AB_MCP_Tools_Options_Settings_Pro', 'option_write_refused' ) ) {
			return AB_MCP_Tools_Options_Settings_Pro::option_write_refused( 'widget_' . $base );
		}
		$row = get_option( 'widget_' . $base, null );
		if ( null !== $row && ! ( is_array( $row ) && ! empty( $row['_multiwidget'] ) ) ) {
			return new WP_Error(
				'ab_mcp_blocked',
				sprintf(
					/* translators: %s: option name */
					__( 'The option %s was not written by the widgets of WordPress, so it is not changed here; nothing was changed. wp_get_widgets lists the widgets and their ids.', 'alphabridge-mcp' ),
					'widget_' . $base
				)
			);
		}
		return false;
	}

	/* ------------------------------------------------------------ the rows */

	/**
	 * The row of an option as the table holds it: its raw value and its
	 * autoload flag; false when there is none, null when the table could
	 * not be read — or when the database matched a row of another spelling
	 * (option_name is compared with a collation that folds case): that row
	 * is not this one, and nothing is written over it.
	 *
	 * @param string $name Option name.
	 * @return array|false|null { value, autoload }
	 */
	public static function row( $name ) {
		global $wpdb;
		if ( ! self::has_tables( array( 'options' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", (string) $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $rows ) {
			return null;
		}
		if ( ! isset( $rows[0] ) ) {
			return false;
		}
		if ( (string) ( $rows[0]['option_name'] ?? '' ) !== (string) $name ) {
			return null;
		}
		return array(
			'value'    => null === ( $rows[0]['option_value'] ?? null ) ? '' : (string) $rows[0]['option_value'],
			'autoload' => (string) ( $rows[0]['autoload'] ?? '' ),
		);
	}

	/**
	 * The state of rows in the form a snapshot keeps it.
	 *
	 * @param array|false $row row().
	 * @return array
	 */
	private static function encode_state( $row ) {
		if ( ! is_array( $row ) ) {
			return array( 'existed' => false );
		}
		$v = (string) $row['value'];
		return array( 'existed' => true ) + ( 1 === preg_match( '//u', $v ) ? array( 'value' => $v ) : array( 'b64' => base64_encode( $v ) ) ) + array( 'autoload' => (string) $row['autoload'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- bytes JSON cannot carry.
	}

	/**
	 * The raw bytes of a state.
	 *
	 * @param array $state encode_state().
	 * @return string|null null for a row that was not there.
	 */
	private static function raw( array $state ) {
		if ( empty( $state['existed'] ) ) {
			return null;
		}
		if ( array_key_exists( 'b64', $state ) ) {
			$raw = base64_decode( (string) $state['b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- bytes JSON cannot carry.
			return false === $raw ? '' : $raw;
		}
		return (string) $state['value'];
	}

	/**
	 * sha256 of a state: whether the row is there, and its bytes. The flag
	 * does not count.
	 *
	 * @param array $state encode_state().
	 * @return string
	 */
	private static function hash_state( array $state ) {
		$raw = self::raw( $state );
		return null === $raw ? self::ABSENT : hash( 'sha256', $raw );
	}

	/**
	 * The value of raw bytes as WordPress would read them, without creating
	 * an object of any class: only to look at, never to write.
	 *
	 * @param string|null $raw Raw bytes.
	 * @return mixed
	 */
	private static function peek( $raw ) {
		if ( null === $raw ) {
			return null;
		}
		$trim = trim( $raw );
		if ( 'b:0;' === $trim ) {
			return false;
		}
		$value = @unserialize( $trim, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- objects become incomplete, nothing of a class runs.
		return false === $value ? $raw : $value;
	}

	/**
	 * The snapshot before a write: each row named, as stored. null when one
	 * could not be read: a row read as missing would be deleted by the
	 * restore.
	 *
	 * @param string   $base  Widget base id.
	 * @param string[] $names Option names.
	 * @return array|null
	 */
	public static function take( $base, array $names ) {
		if ( ! self::on() ) {
			return null;
		}
		$snap = array(
			'v'    => self::VERSION,
			'base' => (string) $base,
			'rows' => array(),
		);
		foreach ( $names as $name ) {
			$row = self::row( (string) $name );
			if ( null === $row ) {
				return null;
			}
			$snap['rows'][ (string) $name ] = self::encode_state( $row );
		}
		return $snap;
	}

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>|null
	 */
	public static function now_hashes( array $snap ) {
		$out = array();
		foreach ( array_keys( (array) $snap['rows'] ) as $name ) {
			$row = self::row( (string) $name );
			if ( null === $row ) {
				return null;
			}
			$out[ 'option:' . $name ] = self::hash_state( self::encode_state( $row ) );
		}
		return $out;
	}

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>
	 */
	public static function before_hashes( array $snap ) {
		$out = array();
		foreach ( (array) $snap['rows'] as $name => $state ) {
			$out[ 'option:' . $name ] = self::hash_state( (array) $state );
		}
		return $out;
	}

	/**
	 * @param array $snap Snapshot.
	 * @return string
	 */
	public static function ref( array $snap ) {
		return 'widget_' . (string) $snap['base'];
	}

	/* ------------------------------------------------------------ recording */

	/**
	 * The undo and read-back part of the answer of a widget tool, after its
	 * write held.
	 *
	 * @param array $spec { tool, snap (take(), or null), summary, asked?: field
	 *                    => value as sent, saved?: field => value as stored }.
	 *                    Without asked, no read-back (a deletion).
	 * @return array
	 */
	public static function answer( array $spec ) {
		$tool   = (string) $spec['tool'];
		$reader = 'wp_get_widgets';
		$snap   = isset( $spec['snap'] ) && is_array( $spec['snap'] ) ? $spec['snap'] : null;
		$after  = null !== $snap ? self::now_hashes( $snap ) : null;
		$asked  = isset( $spec['asked'] ) ? (array) $spec['asked'] : null;
		$saved  = isset( $spec['saved'] ) ? (array) $spec['saved'] : array();
		// Changed or not: by the rows where they could be read; else, as a
		// write that held is all the tool knows, as changed.
		$changed = null !== $after ? $after !== self::before_hashes( $snap ) : true;
		if ( null !== $snap && null !== $after ) {
			$snap['after'] = $after;
		} else {
			$snap = null;
		}
		$outcome = self::outcome( $tool, $reader, $changed, $snap, (string) $spec['summary'] );
		if ( null === $asked ) {
			return AB_MCP_Undo_Answer::with( array(), $outcome );
		}
		// wp_get_widgets leaves out every value under a credential-like key,
		// at any depth: such a field is compared, never shown. A value that
		// is not plain (an object a plugin stored) is compared as different
		// and not shown either.
		$hidden = array();
		foreach ( $asked as $field => $want ) {
			$have = array_key_exists( $field, $saved ) ? $saved[ $field ] : null;
			if ( self::sensitive( (string) $field ) || self::holds_credential( $want ) || self::holds_credential( $have ) || ! self::plain( $have ) ) {
				$hidden[] = (string) $field;
			}
			// What the widget stores as text and was sent as a number (or
			// the reverse) is no difference.
			if ( array_key_exists( $field, $saved ) && self::plain( $have ) && self::same( $have, $want ) ) {
				$asked[ $field ] = $have;
			}
		}
		// A value that is no value JSON carries reads as not found: never
		// equal to what was asked, never cast to show it.
		$plain_saved = array();
		foreach ( $saved as $field => $value ) {
			if ( self::plain( $value ) ) {
				$plain_saved[ $field ] = $value;
			}
		}
		$res = AB_MCP_Undo_Answer::saved( array(), $asked, $plain_saved, $reader, $hidden );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * The settings of one widget as stored now, read again: what saved_fields
	 * compares with what was sent.
	 *
	 * @param string $base Widget base id.
	 * @param int    $num  Instance number.
	 * @return array
	 */
	public static function instance( $base, $num ) {
		$opt = get_option( 'widget_' . $base );
		return is_array( $opt ) && isset( $opt[ $num ] ) && is_array( $opt[ $num ] ) ? $opt[ $num ] : array();
	}

	/**
	 * Does a value hold a credential-like key at any depth?
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function holds_credential( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $k => $v ) {
			if ( self::sensitive( (string) $k ) || self::holds_credential( $v ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is a key one wp_get_widgets leaves out?
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function sensitive( $key ) {
		self::rules();
		return AB_MCP_Undo_Widget_Rules::key_hidden( $key );
	}

	/**
	 * Load the rules of the widget tools (AB_MCP_Undo_Widget_Rules).
	 */
	private static function rules() {
		if ( ! class_exists( 'AB_MCP_Undo_Widget_Rules', false ) ) {
			require_once __DIR__ . '/class-undo-widget-rules.php';
		}
	}

	/* ------------------------------------------------------------- restore */

	/**
	 * @param string $tool Tool of the point.
	 * @param array  $snap Snapshot (valid()).
	 * @return string
	 */
	public static function refusal( $tool, array $snap ) {
		$base = (string) $snap['base'];
		$name = 'widget_' . $base;
		if ( self::option_refusal( $base ) ) {
			return 'protected_now';
		}
		// valid() let only rows through in the form the widgets of
		// WordPress write them (canonical_rows()).
		$old = self::peek( self::raw( (array) $snap['rows'][ $name ] ) );
		if ( null === $old || current_user_can( 'unfiltered_html' ) ) {
			return '';
		}
		// Each widget whose settings change has to come out of its own
		// update() as it is: nothing the account could not store itself.
		$now = self::row( $name );
		$now = is_array( $now ) ? self::peek( $now['value'] ) : array();
		$now = is_array( $now ) ? $now : array();
		self::rules();
		foreach ( $old as $num => $instance ) {
			// Every instance is checked; only the mark is no instance.
			if ( '_multiwidget' === $num ) {
				continue;
			}
			$current = isset( $now[ $num ] ) && is_array( $now[ $num ] ) ? $now[ $num ] : array();
			if ( isset( $now[ $num ] ) && self::plain( $instance ) && self::plain( $now[ $num ] ) && self::same( $instance, $now[ $num ] ) ) {
				continue;
			}
			$clean = AB_MCP_Undo_Widget_Rules::sanitize_instance( $base, $num, $instance, $current );
			if ( ! is_array( $clean ) || ! self::plain( $clean ) || ! self::same( $clean, $instance ) ) {
				return 'forbidden';
			}
		}
		return '';
	}

	/**
	 * Put the rows back that differ, then read each again. A widget's
	 * settings go back before the map that names it, and the map goes before
	 * the settings of a widget the change added are removed: no sidebar
	 * names a widget without settings on the way.
	 *
	 * @param array    $snap Snapshot.
	 * @param string[] $locs Locations that differ.
	 * @return true|WP_Error
	 */
	protected static function put_back( array $snap, array $locs ) {
		$name = 'widget_' . (string) $snap['base'];
		// Does the restore take settings away (an added widget)? Then the
		// map goes first; else the settings do.
		$old  = self::peek( self::raw( (array) $snap['rows'][ $name ] ) );
		$now  = self::row( $name );
		$now  = is_array( $now ) ? self::peek( $now['value'] ) : null;
		$less = is_array( $now ) && array() !== array_diff_key( array_filter( $now, 'is_int', ARRAY_FILTER_USE_KEY ), is_array( $old ) ? $old : array() );
		foreach ( $less ? array( self::MAP, $name ) : array( $name, self::MAP ) as $option ) {
			if ( ! in_array( 'option:' . $option, $locs, true ) ) {
				continue;
			}
			$state = (array) $snap['rows'][ $option ];
			if ( ! self::write_row( $option, $state ) || self::hash_state( self::encode_state( self::row( $option ) ) ) !== self::hash_state( $state ) ) {
				return new WP_Error(
					'ab_mcp_undo_write_failed',
					sprintf(
						/* translators: %s: option name */
						__( 'The database did not take back the option %s; the undo point stays. Try again; wp_get_widgets shows the widgets as they are now.', 'alphabridge-mcp' ),
						$option
					)
				);
			}
		}
		return true;
	}

	/**
	 * Write one row back as the snapshot holds it, then drop the copies the
	 * options cache and the request keep.
	 *
	 * @param string $name  Option name.
	 * @param array  $state encode_state().
	 * @return bool
	 */
	private static function write_row( $name, array $state ) {
		global $wpdb;
		$now = self::row( $name );
		if ( null === $now ) {
			return false;
		}
		$raw = self::raw( $state );
		if ( null === $raw ) {
			$done = false === $now ? 0 : $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( false === $now ) {
			$flag = AB_MCP_Undo::normalize_autoload( (string) ( $state['autoload'] ?? '' ) ) ? (string) $state['autoload'] : 'no';
			$done = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw, 'autoload' => $flag ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$done = $wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( self::MAP === $name ) {
			// The copy wp_get_sidebars_widgets() keeps for the request.
			$GLOBALS['_wp_sidebars_widgets'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the request copy of the core, cleared as wp_set_sidebars_widgets() clears it.
		}
		return false !== $done;
	}

	/**
	 * @return WP_Error
	 */
	protected static function changed_error() {
		return new WP_Error( 'ab_mcp_undo_widget_changed', __( 'The widgets were changed after the undo was planned; nothing was restored. Pass force=true to put the old state back over the newer change.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error
	 */
	protected static function corrupt() {
		return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot of the widgets is not in a form this tool writes; nothing was restored. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/**
	 * @param string $tool Tool of the point.
	 * @param mixed  $data Snapshot.
	 * @return bool
	 */
	public static function valid( $tool, $data ) {
		if ( ! isset( self::TOOLS[ $tool ] ) || ! is_array( $data ) ) {
			return false;
		}
		$keys = array_keys( $data );
		sort( $keys );
		if ( array( 'after', 'base', 'rows', 'v' ) !== $keys || self::VERSION !== $data['v'] || ! is_array( $data['rows'] ) || ! is_array( $data['after'] ) ) {
			return false;
		}
		$base = $data['base'];
		if ( ! is_string( $base ) || '' === $base || strlen( 'widget_' . $base ) > 191 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $base ) ) {
			return false;
		}
		$names = array( 'widget_' . $base );
		if ( self::TOOLS[ $tool ] ) {
			$names[] = self::MAP;
		}
		$have = array_map( 'strval', array_keys( $data['rows'] ) );
		$want = $names;
		sort( $have );
		sort( $want );
		if ( $have !== $want ) {
			return false;
		}
		$locs = array();
		foreach ( $names as $name ) {
			$state = $data['rows'][ $name ];
			if ( ! is_array( $state ) || ! isset( $state['existed'] ) || ! is_bool( $state['existed'] ) ) {
				return false;
			}
			if ( ! $state['existed'] ) {
				if ( array( 'existed' ) !== array_keys( $state ) ) {
					return false;
				}
			} else {
				$has_value = array_key_exists( 'value', $state );
				$has_b64   = array_key_exists( 'b64', $state );
				if ( $has_value === $has_b64 || ( $has_value && ! is_string( $state['value'] ) ) || ( $has_b64 && ( ! is_string( $state['b64'] ) || false === base64_decode( $state['b64'], true ) ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					|| ! isset( $state['autoload'] ) || ! is_string( $state['autoload'] ) || null === AB_MCP_Undo::normalize_autoload( $state['autoload'] ) || 3 !== count( $state ) ) {
					return false;
				}
			}
			$locs[] = 'option:' . $name;
		}
		$after = array_keys( $data['after'] );
		sort( $after );
		sort( $locs );
		if ( $after !== $locs ) {
			return false;
		}
		foreach ( $data['after'] as $sha ) {
			if ( ! is_string( $sha ) || ( self::ABSENT !== $sha && 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $sha ) ) ) {
				return false;
			}
		}
		// An update changes the settings of a widget there was: its row
		// was there.
		if ( 'wp_update_widget' === $tool && empty( $data['rows'][ 'widget_' . $base ]['existed'] ) ) {
			return false;
		}
		return self::canonical_rows( $data );
	}

	/**
	 * Do the rows of a snapshot have exactly the form the widgets of
	 * WordPress write? The row of the type: a list marked _multiwidget whose
	 * every other key is an instance number as WordPress writes it (a
	 * positive whole number, never "02" or "2a") holding a list of settings.
	 * The map: a list of sidebars, each a list of widget ids
	 * "<id_base>-<number>" with such a number, and its array_version. Any
	 * other form is refused whole: no check is ever skipped because a value
	 * has a shape it does not expect.
	 *
	 * @param array $data Snapshot.
	 * @return bool
	 */
	private static function canonical_rows( array $data ) {
		foreach ( $data['rows'] as $name => $state ) {
			if ( empty( $state['existed'] ) ) {
				continue;
			}
			$value = self::peek( self::raw( (array) $state ) );
			if ( ! is_array( $value ) ) {
				return false;
			}
			if ( self::MAP === (string) $name ) {
				foreach ( $value as $sidebar => $ids ) {
					if ( 'array_version' === $sidebar ) {
						if ( ! is_int( $ids ) ) {
							return false;
						}
						continue;
					}
					if ( ! is_string( $sidebar ) || ! is_array( $ids ) ) {
						return false;
					}
					foreach ( $ids as $wid ) {
						if ( ! is_string( $wid ) || 1 !== preg_match( '/\A.+-[1-9][0-9]*\z/', $wid ) ) {
							return false;
						}
					}
				}
				continue;
			}
			if ( empty( $value['_multiwidget'] ) ) {
				return false;
			}
			foreach ( $value as $num => $instance ) {
				if ( '_multiwidget' === $num ) {
					continue;
				}
				if ( ! is_int( $num ) || $num <= 0 || ! is_array( $instance ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
