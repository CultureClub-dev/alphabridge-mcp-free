<?php
/**
 * The undo of a change a tool made to one object through WordPress — a term
 * or a menu (a menu is a term), a menu item, the option rows of a widget —
 * since 4.13.0: the half that AB_MCP_Undo_Terms, AB_MCP_Undo_Menu_Items and
 * AB_MCP_Undo_Widgets share.
 *
 * The rule is AB_MCP_Undo_Meta's. Each kind reads the state of its object
 * straight from the tables, before the write (or, for an object the tool
 * creates, right after it), and keeps in the snapshot under "after" the
 * sha256 of each location as the change left it. wp_undo restores a point
 * only where each location still has that state — or the one from before,
 * where there is nothing to do — and refuses one somebody changed since,
 * unless force is set. The rights and rules of the tool are asked again at
 * the restore, on the object as the database has it now (refusal()), and a
 * snapshot in another form than the tool writes is refused whole (valid()).
 *
 * Answers about a point (wp_undo_list, a dry run, a refusal) name ids,
 * option names and locations, never values.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-undo-answer.php';

/**
 * Class AB_MCP_Undo_Objects
 */
abstract class AB_MCP_Undo_Objects {

	/** Snapshot format version. */
	const VERSION = 1;

	/** The "hash" of a location whose object is not there. */
	const ABSENT = 'absent';

	/** The undo kind; each subclass names its own. */
	const KIND = '';

	/* ---------------------------------------------- what each kind supplies */

	/**
	 * Does the snapshot have the form the tool writes?
	 *
	 * @param string $tool Tool of the point.
	 * @param mixed  $data Snapshot.
	 * @return bool
	 */
	abstract public static function valid( $tool, $data );

	/**
	 * Why the account may not restore this point now, '' when it may.
	 *
	 * @param string $tool Tool of the point.
	 * @param array  $snap Snapshot (valid()).
	 * @return string gone, forbidden or protected_now; '' when allowed.
	 */
	abstract public static function refusal( $tool, array $snap );

	/**
	 * sha256 of each location as it is now (ABSENT where the object is not
	 * there); null when a table could not be read.
	 *
	 * @param array $snap Snapshot.
	 * @return array<string,string>|null
	 */
	abstract public static function now_hashes( array $snap );

	/**
	 * sha256 of each location as it was before the change.
	 *
	 * @param array $snap Snapshot.
	 * @return array<string,string>
	 */
	abstract public static function before_hashes( array $snap );

	/**
	 * The ref of the point's one item, as wp_undo reports it.
	 *
	 * @param array $snap Snapshot.
	 * @return string
	 */
	abstract public static function ref( array $snap );

	/**
	 * Put the locations named back, and read them again.
	 *
	 * @param array    $snap Snapshot.
	 * @param string[] $locs Locations that differ from before.
	 * @return true|WP_Error
	 */
	abstract protected static function put_back( array $snap, array $locs );

	/**
	 * The answer to an object changed between the plan and the write.
	 *
	 * @return WP_Error
	 */
	abstract protected static function changed_error();

	/**
	 * The answer to a snapshot in a form the tool does not write.
	 *
	 * @return WP_Error
	 */
	abstract protected static function corrupt();

	/* ------------------------------------------------------------ restore */

	/**
	 * Plan the restore of a point: its one item, or why it is blocked.
	 *
	 * @param string $tool  Tool of the point.
	 * @param mixed  $data  Snapshot.
	 * @param bool   $force Restore over later changes.
	 * @return array|WP_Error { tool, kind, items, blocked, restorable }
	 */
	public static function plan( $tool, $data, $force ) {
		if ( ! static::valid( $tool, $data ) ) {
			return static::corrupt();
		}
		$out = array(
			'tool'       => (string) $tool,
			'kind'       => static::KIND,
			'items'      => array(),
			'blocked'    => array(),
			'restorable' => 0,
		);
		$ref = static::ref( $data );
		$cmp = static::compare( $data );
		if ( null === $cmp ) {
			$out['blocked'][] = array(
				'ref'    => $ref,
				'reason' => 'unreadable',
			);
			return $out;
		}
		$noop = array() === array_diff( $cmp, array( 'before' ) );
		// Nothing to write needs no right to write it.
		$why = $noop ? '' : static::refusal( (string) $tool, $data );
		if ( '' !== $why ) {
			$out['blocked'][] = array(
				'ref'    => $ref,
				'reason' => $why,
			);
			return $out;
		}
		$changed = array_keys( $cmp, 'changed', true );
		if ( array() !== $changed && ! $force ) {
			$out['blocked'][] = array(
				'ref'    => $ref,
				'reason' => 'changed_since',
				'locs'   => $changed,
			);
			return $out;
		}
		$out['items'][]    = array(
			'ref'   => $ref,
			'snap'  => $data,
			'force' => (bool) $force,
			'noop'  => $noop,
		);
		$out['restorable'] = 1;
		return $out;
	}

	/**
	 * How the object stands against the change: per location 'before',
	 * 'after' or 'changed'. null when a table could not be read.
	 *
	 * @param array $snap Snapshot.
	 * @return array<string,string>|null
	 */
	public static function compare( array $snap ) {
		$now = static::now_hashes( $snap );
		if ( null === $now ) {
			return null;
		}
		$before = static::before_hashes( $snap );
		$after  = isset( $snap['after'] ) ? (array) $snap['after'] : array();
		$out    = array();
		foreach ( $now as $loc => $sha ) {
			$loc = (string) $loc;
			if ( isset( $before[ $loc ] ) && hash_equals( (string) $before[ $loc ], (string) $sha ) ) {
				$out[ $loc ] = 'before';
			} elseif ( isset( $after[ $loc ] ) && hash_equals( (string) $after[ $loc ], (string) $sha ) ) {
				$out[ $loc ] = 'after';
			} else {
				$out[ $loc ] = 'changed';
			}
		}
		return $out;
	}

	/**
	 * Restore one planned item. The comparison is made again right before
	 * the write: the plan was a moment ago.
	 *
	 * @param array $item Item of plan().
	 * @return true|WP_Error
	 */
	public static function restore( array $item ) {
		$snap = (array) $item['snap'];
		$cmp  = static::compare( $snap );
		if ( null === $cmp ) {
			return new WP_Error( 'ab_mcp_undo_write_failed', __( 'The state could not be read before the restore; nothing was restored. Try again in a moment; the undo point stays.', 'alphabridge-mcp' ) );
		}
		if ( empty( $item['force'] ) && in_array( 'changed', $cmp, true ) ) {
			return static::changed_error();
		}
		$locs = array_keys( array_diff( $cmp, array( 'before' ) ) );
		if ( array() === $locs ) {
			return true;
		}
		return static::put_back( $snap, array_map( 'strval', $locs ) );
	}

	/* ------------------------------------------------------------ recording */

	/**
	 * The undo outcome of a write, for AB_MCP_Undo_Answer::with(): the token
	 * of a new point, or why there is none. The order is that of
	 * AB_MCP_Undo_Meta::write(): undo switched off, nothing changed, Pro's
	 * own wp_undo, a state that could not be read, then the point.
	 *
	 * @param string     $tool     Tool name.
	 * @param string     $reader   The tool that shows the result.
	 * @param bool       $changed  Whether the write changed anything.
	 * @param array|null $snap     Snapshot with "after", or null when the state could not be read.
	 * @param string     $summary  Summary for wp_undo_list (ids, never values).
	 * @return string|WP_Error|null
	 */
	public static function outcome( $tool, $reader, $changed, $snap, $summary ) {
		if ( ! class_exists( 'AB_MCP_Undo' ) || ! AB_MCP_Undo::enabled() ) {
			return class_exists( 'AB_MCP_Undo' ) ? AB_MCP_Undo::record_result( array() ) : null;
		}
		if ( ! $changed ) {
			return AB_MCP_Undo_Answer::nothing_changed( $reader );
		}
		if ( AB_MCP_Undo_Answer::pro_cannot_undo( $tool ) ) {
			return AB_MCP_Undo_Answer::pro_note( $tool, $reader );
		}
		if ( ! is_array( $snap ) || ! isset( $snap['after'] ) ) {
			return AB_MCP_Undo_Answer::unreadable( $reader );
		}
		$policy = class_exists( 'AB_MCP_Undo_Restore' ) ? AB_MCP_Undo_Restore::policy() : array();
		return AB_MCP_Undo::record_result(
			array(
				'tool'    => (string) $tool,
				'cap'     => isset( $policy[ $tool ]['cap'] ) ? (string) $policy[ $tool ]['cap'] : 'manage_options',
				'kind'    => static::KIND,
				'summary' => (string) $summary,
				'items'   => 1,
				'data'    => $snap,
			)
		);
	}

	/**
	 * Is undo on, so a snapshot is worth taking?
	 *
	 * @return bool
	 */
	protected static function on() {
		return class_exists( 'AB_MCP_Undo' ) && AB_MCP_Undo::enabled();
	}

	/* ------------------------------------------------------------- helpers */

	/**
	 * sha256 of a value, the same bytes on every call.
	 *
	 * @param mixed $value Text, numbers, lists.
	 * @return string
	 */
	protected static function hash( $value ) {
		return hash( 'sha256', (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the same bytes on every call, without WordPress' re-encoding.
	}

	/**
	 * Run one SELECT and hand back its rows as arrays; null when it could
	 * not run (query() answers false, or with an error). The same three
	 * answers as AB_MCP_Undo_Meta::rows(): rows, none, could not ask.
	 *
	 * @param string $sql Prepared statement.
	 * @return array[]|null
	 */
	protected static function select( $sql ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return null;
		}
		$ran = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared by the caller; the stored state is the point.
		if ( false === $ran || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( is_array( $wpdb->last_result ) ? $wpdb->last_result : array() as $row ) {
			$out[] = (array) $row;
		}
		return $out;
	}

	/**
	 * Does $wpdb have these tables to ask?
	 *
	 * @param string[] $tables Properties of $wpdb.
	 * @return bool
	 */
	protected static function has_tables( array $tables ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		foreach ( $tables as $t ) {
			if ( empty( $wpdb->$t ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A value of only text, numbers, true, false, null and lists of them —
	 * what an answer can show and compare without casting an object.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function plain( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! self::plain( $v ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $value || is_scalar( $value );
	}

	/**
	 * Whether a value WordPress read back is what was sent, the way it
	 * stores it: a scalar as its text (true as "1", false and null as ""),
	 * a list key by key.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 * @return bool
	 */
	public static function same( $a, $b ) {
		if ( is_array( $a ) || is_array( $b ) ) {
			if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) ) {
				return false;
			}
			foreach ( $a as $k => $v ) {
				if ( ! array_key_exists( $k, $b ) || ! self::same( $v, $b[ $k ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( ! ( null === $a || is_scalar( $a ) ) || ! ( null === $b || is_scalar( $b ) ) ) {
			return false;
		}
		return AB_MCP_Undo_Answer::stored_form( $a ) === AB_MCP_Undo_Answer::stored_form( $b );
	}

	/* --------------------------------------------------- deletes without undo */

	/**
	 * The undo_note of the tools that delete for good, by tool: why there is
	 * no way back, and what to do first.
	 *
	 * @param string $tool wp_delete_term, wp_delete_menu_item, wp_delete_media or wp_delete_user.
	 * @return string
	 */
	public static function deleted_for_good( $tool ) {
		switch ( (string) $tool ) {
			case 'wp_delete_term':
				$why = __( 'WordPress deletes a term for good, with its meta and its place on every post that had it. Before deleting a term that may be needed again, note it with wp_get_term and wp_get_term_meta; wp_create_term makes it anew, under a new id and on no post.', 'alphabridge-mcp' );
				break;
			case 'wp_delete_menu_item':
				$why = __( 'WordPress deletes a menu item for good, not into the trash. Before deleting one that may be needed again, note it with wp_get_menu; wp_add_menu_item adds it anew, under a new id and at the end of the menu.', 'alphabridge-mcp' );
				break;
			case 'wp_delete_media':
				$why = __( 'WordPress deletes the file, its image sizes and its entry in the media library for good. Before deleting a file that may be needed again, keep a copy of it (wp_get_media gives its address); wp_upload_media adds it anew, under a new id and address.', 'alphabridge-mcp' );
				break;
			default:
				$why = __( 'WordPress deletes the account for good, and its content as the call said. Before deleting an account that may be needed again, note it with wp_get_user and wp_get_user_meta; wp_create_user creates a new one, under a new id and with a new password.', 'alphabridge-mcp' );
		}
		return AB_MCP_Undo_Answer::not_undoable( $why );
	}

	/**
	 * The notes of deleted_for_good(), for the check that each names a way
	 * (AnswerWayTest).
	 *
	 * @return string[]
	 */
	public static function own_notes() {
		$out = array();
		foreach ( array( 'wp_delete_term', 'wp_delete_menu_item', 'wp_delete_media', 'wp_delete_user' ) as $tool ) {
			$out[ $tool ] = self::deleted_for_good( $tool );
		}
		return $out;
	}
}
