<?php
/**
 * Undo kind meta: the rows of a meta key of a post, a term or a user, taken
 * before a meta tool changes them, and put back byte for byte (since 4.13.0).
 *
 * The same approach as the meta rows of AB_MCP_Undo_Builder, for three
 * tables instead of one: every row of the key with its id, straight from the
 * table — a key can hold several rows, and get_*_meta( …, true ) reads the
 * one with the lowest id, so the order counts as well as the values — and on
 * the way back each row under its old id where that id is free, the rows the
 * change added removed again, a key the change created deleted again. The
 * raw bytes and not update_metadata(): that would unslash, sanitise and
 * serialise a serialized value a second time, and what came back would not
 * be what was there. wp_update_media keeps the title and caption of the file
 * in the same undo point; they go back through the write of the undo of
 * wp_update_post (AB_MCP_Undo_Restore::restore_post_fields()).
 *
 * Snapshot (one point, one object):
 *   { "v": 1, "type": "post"|"term"|"user", "id": int,
 *     "keys":   { "<key>": { "existed": false }
 *                        | { "existed": true, "rows": [ { "meta_id": int, "value"|"b64": … }, … ] } },
 *     "fields": { "post_title"|"post_excerpt": string },   wp_update_media only
 *     "after":  { "meta:<key>"|"field:<column>": sha256 } }
 * "after" is the state the change left, read right after the write. wp_undo
 * restores a key or field only where it still has that state — or the one
 * from before, where there is nothing to do — and refuses one somebody has
 * changed since, unless force is set.
 *
 * Only the keys a tool may write get here, so the snapshot holds no value of
 * a protected or credential-like key; the restore asks the tool's own rules
 * again (AB_MCP_Undo_Meta_Rules), so neither does a hand-edited one reach
 * such a key. Answers about an undo point (wp_undo_list, a dry run, a
 * refusal) name keys and ids, never values.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-undo-answer.php';

/**
 * Class AB_MCP_Undo_Meta
 */
class AB_MCP_Undo_Meta {

	/** The undo kind. */
	const KIND = 'meta';

	/** Snapshot format version. */
	const VERSION = 1;

	/** The key wp_update_media keeps a file's alternative text in. */
	const MEDIA_ALT = '_wp_attachment_image_alt';

	/** The post columns wp_update_media writes: title and caption. */
	const MEDIA_FIELDS = array( 'post_title', 'post_excerpt' );

	/**
	 * The meta table of each type: its property on $wpdb, the column of the
	 * object and the column of the row id (user meta has its own name).
	 */
	const TABLES = array(
		'post' => array( 'table' => 'postmeta', 'col' => 'post_id', 'pk' => 'meta_id' ),
		'term' => array( 'table' => 'termmeta', 'col' => 'term_id', 'pk' => 'meta_id' ),
		'user' => array( 'table' => 'usermeta', 'col' => 'user_id', 'pk' => 'umeta_id' ),
	);

	/** The tools that record this kind, and the type of object each writes to. */
	const TOOLS = array(
		'wp_update_post_meta' => 'post',
		'wp_update_term_meta' => 'term',
		'wp_update_user_meta' => 'user',
		'wp_update_media'     => 'post',
	);

	/* ------------------------------------------------------------ the write */

	/**
	 * Write through a tool and answer for it: the rows before, the write, the
	 * read-back, the undo point. Shared by the four tools, so they cannot
	 * drift apart.
	 *
	 * A write that changed nothing while the site does not hold what was
	 * asked is an error: WordPress or a plugin refused it, and «updated»
	 * would be false. A write that changed something, but not to what was
	 * asked (a sanitize callback, a kses filter), answers as written, with
	 * the stored values, saved_differs and a warning — and an undo point,
	 * since something did change.
	 *
	 * @param array    $spec  { tool, type, id, keys: key => value in
	 *                        AB_MCP_Undo_Answer::stored_form(), fields: column
	 *                        => text (wp_update_media), names: key|column =>
	 *                        name in the answer, hidden: names not shown,
	 *                        reader: the tool that reads them, summary }.
	 * @param callable $write Does the write; may return a WP_Error, which is
	 *                        passed on as it is.
	 * @return array|WP_Error { saved_fields, saved_fields_omitted?, saved_differs?, warning?, undo, undo_note? }
	 */
	public static function write( array $spec, callable $write ) {
		$tool   = (string) $spec['tool'];
		$type   = (string) $spec['type'];
		$id     = (int) $spec['id'];
		$want   = isset( $spec['keys'] ) ? (array) $spec['keys'] : array();
		$fwant  = isset( $spec['fields'] ) ? (array) $spec['fields'] : array();
		$names  = isset( $spec['names'] ) ? (array) $spec['names'] : array();
		$reader = isset( $spec['reader'] ) ? (string) $spec['reader'] : '';
		$keys   = array_map( 'strval', array_keys( $want ) );
		$cols   = array_map( 'strval', array_keys( $fwant ) );
		$on     = class_exists( 'AB_MCP_Undo' ) && AB_MCP_Undo::enabled();

		// The rows as stored, right before the write. Not taken where undo is
		// off: nothing would keep them.
		$snap   = $on ? self::take( $type, $id, $keys, $cols ) : null;
		$before = self::read_back( $type, $id, $keys, $cols );
		$done   = call_user_func( $write );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$after = self::read_back( $type, $id, $keys, $cols );

		$asked = array();
		$saved = array();
		foreach ( array_merge( $fwant, $want ) as $what => $value ) {
			$name           = isset( $names[ $what ] ) ? (string) $names[ $what ] : (string) $what;
			$asked[ $name ] = $value;
			if ( array_key_exists( $what, $after ) ) {
				$saved[ $name ] = $after[ $what ];
			}
		}

		// Changed or not: by the rows themselves where they could be read,
		// else by what WordPress reads back.
		$hashes  = null !== $snap ? self::state_hashes( $type, $id, $keys, $cols ) : null;
		$changed = null !== $hashes ? $hashes !== self::before_hashes( $snap ) : $after !== $before;
		$res     = AB_MCP_Undo_Answer::saved( array(), $asked, $saved, $reader, isset( $spec['hidden'] ) ? (array) $spec['hidden'] : array() );
		if ( ! $changed && isset( $res['saved_differs'] ) ) {
			return new WP_Error(
				'ab_mcp_not_saved',
				sprintf(
					/* translators: 1: comma-separated field names, 2: tool name */
					__( 'WordPress did not store the change: %1$s still hold what they held before. A plugin may refuse it on save (a filter on the meta update, or a rule its plugin registered for the key). Check the value with %2$s, and ask the site administrator which plugin guards it.', 'alphabridge-mcp' ),
					implode( ', ', $res['saved_differs'] ),
					$reader
				)
			);
		}

		if ( ! $on ) {
			$outcome = class_exists( 'AB_MCP_Undo' ) ? AB_MCP_Undo::record_result( array() ) : null;
		} elseif ( ! $changed ) {
			$outcome = AB_MCP_Undo_Answer::nothing_changed( $reader );
		} elseif ( AB_MCP_Undo_Answer::pro_cannot_undo( $tool ) ) {
			$outcome = AB_MCP_Undo_Answer::pro_note( $tool, $reader );
		} elseif ( null === $snap || null === $hashes ) {
			$outcome = AB_MCP_Undo_Answer::unreadable( $reader );
		} else {
			$snap['after'] = $hashes;
			$outcome       = AB_MCP_Undo::record_result(
				array(
					'tool'    => $tool,
					'cap'     => self::cap_of( $tool ),
					'kind'    => self::KIND,
					'summary' => isset( $spec['summary'] ) ? (string) $spec['summary'] : '',
					'items'   => 1,
					'data'    => $snap,
				)
			);
		}
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * The capability the restore of a tool's point needs: the tool's own.
	 *
	 * @param string $tool Tool name.
	 * @return string
	 */
	private static function cap_of( $tool ) {
		$policy = class_exists( 'AB_MCP_Undo_Restore' ) ? AB_MCP_Undo_Restore::policy() : array();
		return isset( $policy[ $tool ]['cap'] ) ? (string) $policy[ $tool ]['cap'] : 'edit_posts';
	}

	/**
	 * What WordPress reads back for the keys and fields, past the object
	 * cache: per key the value as get_*_meta() gives it (several rows that
	 * differ, as a list), per field the column of the post. A key without a
	 * row, or a post that is gone, is missing from the answer.
	 *
	 * @param string   $type post, term or user.
	 * @param int      $id   Object id.
	 * @param string[] $keys Meta keys.
	 * @param string[] $cols Post columns.
	 * @return array
	 */
	public static function read_back( $type, $id, array $keys, array $cols ) {
		$out = array();
		if ( array() !== $keys ) {
			wp_cache_delete( (int) $id, $type . '_meta' );
		}
		foreach ( $keys as $key ) {
			if ( 'term' === $type ) {
				$values = get_term_meta( (int) $id, $key, false );
			} elseif ( 'user' === $type ) {
				$values = get_user_meta( (int) $id, $key, false );
			} else {
				$values = get_post_meta( (int) $id, $key, false );
			}
			$values = is_array( $values ) ? array_values( $values ) : array();
			if ( array() === $values ) {
				continue;
			}
			$first = $values[0];
			$same  = true;
			foreach ( $values as $v ) {
				$same = $same && $v === $first;
			}
			$out[ $key ] = $same ? $first : $values;
		}
		if ( array() !== $cols ) {
			wp_cache_delete( (int) $id, 'posts' );
			$post = get_post( (int) $id );
			foreach ( $cols as $col ) {
				if ( is_object( $post ) && isset( $post->$col ) ) {
					$out[ $col ] = (string) $post->$col;
				}
			}
		}
		return $out;
	}

	/* --------------------------------------------------------- the snapshot */

	/**
	 * The snapshot before a write: every row of each key, and the columns
	 * named; null when the tables could not be read, since a key read as
	 * missing would be deleted by the restore.
	 *
	 * @param string   $type post, term or user.
	 * @param int      $id   Object id.
	 * @param string[] $keys Meta keys.
	 * @param string[] $cols Post columns (wp_update_media).
	 * @return array|null
	 */
	public static function take( $type, $id, array $keys, array $cols = array() ) {
		$out = array(
			'v'    => self::VERSION,
			'type' => (string) $type,
			'id'   => (int) $id,
			'keys' => array(),
		);
		foreach ( $keys as $key ) {
			$rows = self::rows( $type, $id, $key );
			if ( null === $rows ) {
				return null;
			}
			$out['keys'][ (string) $key ] = self::encode_state( $rows );
		}
		if ( array() !== $cols ) {
			$row = self::post_row( $id, $cols );
			if ( null === $row ) {
				return null;
			}
			$out['fields'] = $row;
		}
		return $out;
	}

	/**
	 * Every row of one meta key, oldest first, straight from the table (the
	 * object cache holds unserialized values and may be stale). Three answers
	 * that have to stay apart, as AB_MCP_Undo::option_row() keeps them: the
	 * rows, none (an empty list), and null when the question could not be
	 * asked — query() and last_result, because a SELECT that ran answers with
	 * its count, 0 included, and false means it was never made.
	 *
	 * @param string $type post, term or user.
	 * @param int    $id   Object id.
	 * @param string $key  Meta key.
	 * @return array<int,array{meta_id:int,value:string}>|null
	 */
	public static function rows( $type, $id, $key ) {
		global $wpdb;
		$t = isset( self::TABLES[ $type ] ) ? self::TABLES[ $type ] : null;
		// No database handle to ask: the lookup could not run.
		if ( null === $t || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || empty( $wpdb->{$t['table']} ) ) {
			return null;
		}
		$table = (string) $wpdb->{$t['table']};
		$ran   = $wpdb->query( $wpdb->prepare( "SELECT {$t['pk']} AS meta_id, meta_value FROM {$table} WHERE {$t['col']} = %d AND meta_key = %s ORDER BY {$t['pk']} ASC", (int) $id, (string) $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table and columns come from TABLES; the stored bytes are the point.
		if ( false === $ran || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( is_array( $wpdb->last_result ) ? $wpdb->last_result : array() as $row ) {
			$row   = (array) $row;
			$out[] = array(
				'meta_id' => (int) $row['meta_id'],
				'value'   => null === $row['meta_value'] ? '' : (string) $row['meta_value'],
			);
		}
		return $out;
	}

	/**
	 * Columns of a post as the table has them, null when the post is gone or
	 * the table could not be read.
	 *
	 * @param int      $id   Post id.
	 * @param string[] $cols Columns of MEDIA_FIELDS.
	 * @return array<string,string>|null
	 */
	public static function post_row( $id, array $cols ) {
		global $wpdb;
		$cols = array_values( array_intersect( self::MEDIA_FIELDS, $cols ) );
		if ( array() === $cols || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || empty( $wpdb->posts ) ) {
			return null;
		}
		$list = implode( ', ', $cols );
		$ran  = $wpdb->query( $wpdb->prepare( "SELECT {$list} FROM {$wpdb->posts} WHERE ID = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- the columns come from MEDIA_FIELDS.
		if ( false === $ran || '' !== (string) $wpdb->last_error || ! is_array( $wpdb->last_result ) || ! isset( $wpdb->last_result[0] ) ) {
			return null;
		}
		$row = (array) $wpdb->last_result[0];
		$out = array();
		foreach ( $cols as $col ) {
			$out[ $col ] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
		}
		return $out;
	}

	/**
	 * sha256 of each key and field as they are now: "meta:<key>" and
	 * "field:<column>". null when a table could not be read.
	 *
	 * @param string   $type post, term or user.
	 * @param int      $id   Object id.
	 * @param string[] $keys Meta keys.
	 * @param string[] $cols Post columns.
	 * @return array<string,string>|null
	 */
	public static function state_hashes( $type, $id, array $keys, array $cols ) {
		$out = array();
		foreach ( $keys as $key ) {
			$rows = self::rows( $type, $id, $key );
			if ( null === $rows ) {
				return null;
			}
			$out[ 'meta:' . $key ] = self::hash_state( self::encode_state( $rows ) );
		}
		if ( array() !== $cols ) {
			$row = self::post_row( $id, $cols );
			if ( null === $row ) {
				return null;
			}
			foreach ( $row as $col => $value ) {
				$out[ 'field:' . $col ] = self::hash_value( $value );
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * sha256 of each key and field of a snapshot: the state before.
	 *
	 * @param array $snap Snapshot.
	 * @return array<string,string>
	 */
	public static function before_hashes( array $snap ) {
		$out = array();
		foreach ( (array) $snap['keys'] as $key => $state ) {
			$out[ 'meta:' . $key ] = self::hash_state( (array) $state );
		}
		foreach ( isset( $snap['fields'] ) ? (array) $snap['fields'] : array() as $col => $value ) {
			$out[ 'field:' . $col ] = self::hash_value( (string) $value );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * sha256 of a key's state. Row ids do not count: a key put back over its
	 * name gets new ones and holds the same values.
	 *
	 * @param array $state encode_state().
	 * @return string
	 */
	public static function hash_state( array $state ) {
		$values = array();
		if ( ! empty( $state['existed'] ) && isset( $state['rows'] ) && is_array( $state['rows'] ) ) {
			foreach ( $state['rows'] as $row ) {
				$values[] = self::encode_value( self::raw_value( (array) $row ) );
			}
		}
		return hash(
			'sha256',
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the same bytes on every call, without WordPress' re-encoding.
				array(
					'existed' => ! empty( $state['existed'] ),
					'values'  => $values,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/**
	 * sha256 of a column's value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function hash_value( $value ) {
		return hash( 'sha256', (string) $value );
	}

	/* ------------------------------------------------------------ restore */

	/**
	 * Plan the restore of a point: the item, or why it is blocked. The rights
	 * are the account's now and the key rules the tool's now; a key that is
	 * protected since is not written.
	 *
	 * @param string $tool  Tool of the point.
	 * @param mixed  $data  Snapshot.
	 * @param bool   $force Restore over later changes.
	 * @return array|WP_Error { items, blocked }
	 */
	public static function plan( $tool, $data, $force ) {
		if ( ! self::valid( $tool, $data ) ) {
			return self::corrupt();
		}
		$ref = self::ref( $data );
		$out = array(
			'items'   => array(),
			'blocked' => array(),
		);
		$why = self::refusal( (string) $tool, $data );
		if ( '' !== $why ) {
			$out['blocked'][] = array(
				'ref'    => $ref,
				'reason' => $why,
			);
			return $out;
		}
		$cmp = self::compare( $data );
		if ( null === $cmp ) {
			$out['blocked'][] = array(
				'ref'    => $ref,
				'reason' => 'unreadable',
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
		$out['items'][] = array(
			'ref'   => $ref,
			'snap'  => $data,
			'force' => (bool) $force,
			'noop'  => array() === array_diff( $cmp, array( 'before' ) ),
		);
		return $out;
	}

	/**
	 * How the object stands against the change: per key and field, 'before'
	 * (as before the change, nothing to restore), 'after' (as the change left
	 * it) or 'changed' (neither: somebody wrote it since). null when a table
	 * could not be read.
	 *
	 * @param array $snap Snapshot.
	 * @return array<string,string>|null
	 */
	public static function compare( array $snap ) {
		$keys = array_map( 'strval', array_keys( (array) $snap['keys'] ) );
		$cols = array_map( 'strval', array_keys( isset( $snap['fields'] ) ? (array) $snap['fields'] : array() ) );
		$now  = self::state_hashes( (string) $snap['type'], (int) $snap['id'], $keys, $cols );
		if ( null === $now ) {
			return null;
		}
		$before = self::before_hashes( $snap );
		$after  = isset( $snap['after'] ) ? (array) $snap['after'] : array();
		$out    = array();
		foreach ( $now as $loc => $sha ) {
			if ( isset( $before[ $loc ] ) && hash_equals( $before[ $loc ], $sha ) ) {
				$out[ $loc ] = 'before';
			} elseif ( isset( $after[ $loc ] ) && hash_equals( (string) $after[ $loc ], $sha ) ) {
				$out[ $loc ] = 'after';
			} else {
				$out[ $loc ] = 'changed';
			}
		}
		return $out;
	}

	/**
	 * Put the rows of the keys back that differ from the snapshot, then check
	 * that each holds its old state. The columns of the post are not written
	 * here (AB_MCP_Undo_Restore::restore_meta()).
	 *
	 * @param array    $snap Snapshot.
	 * @param string[] $keys Keys to write.
	 * @return true|WP_Error
	 */
	public static function write_back( array $snap, array $keys ) {
		global $wpdb;
		$type = (string) $snap['type'];
		$id   = (int) $snap['id'];
		$t    = self::TABLES[ $type ];
		foreach ( $keys as $key ) {
			$key   = (string) $key;
			$state = (array) $snap['keys'][ $key ];
			$now   = self::rows( $type, $id, $key );
			if ( null === $now ) {
				return self::failed( $key );
			}
			if ( empty( $state['existed'] ) ) {
				// The change created the key: it goes again.
				if ( array() !== $now && false === $wpdb->delete( $wpdb->{$t['table']}, array( $t['col'] => $id, 'meta_key' => $key ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					return self::failed( $key );
				}
				continue;
			}
			$rows = array();
			foreach ( $state['rows'] as $row ) {
				$rows[] = array(
					'meta_id' => (int) $row['meta_id'],
					'value'   => self::raw_value( (array) $row ),
				);
			}
			if ( ! self::restore_rows( $type, $id, $key, $rows, $now ) ) {
				return self::failed( $key );
			}
		}
		wp_cache_delete( $id, $type . '_meta' );
		// What update_metadata() would have done after its write: queries
		// that filter by meta are cached by the "last changed" of the type.
		$last = array(
			'post' => 'wp_cache_set_posts_last_changed',
			'term' => 'wp_cache_set_terms_last_changed',
			'user' => 'wp_cache_set_users_last_changed',
		);
		if ( function_exists( $last[ $type ] ) ) {
			call_user_func( $last[ $type ] );
		}
		if ( 'post' === $type ) {
			clean_post_cache( $id );
		}
		// Byte for byte, or it is not a restore.
		$cmp = self::compare( $snap );
		$off = array();
		foreach ( $keys as $key ) {
			if ( null === $cmp || ! isset( $cmp[ 'meta:' . $key ] ) || 'before' !== $cmp[ 'meta:' . $key ] ) {
				$off[] = (string) $key;
			}
		}
		if ( array() !== $off ) {
			return new WP_Error(
				'ab_mcp_undo_meta_mismatch',
				sprintf(
					/* translators: %s: comma-separated meta keys */
					__( 'The meta was written back, but the database holds something else for: %s. Check the value with the reading tool of its object (wp_get_post_meta, wp_get_term_meta, wp_get_user_meta or wp_get_media).', 'alphabridge-mcp' ),
					implode( ', ', $off )
				)
			);
		}
		return true;
	}

	/**
	 * Put the rows of one key back, as AB_MCP_Undo_Builder::restore_rows()
	 * does for a page: every row under its own id where the row is still
	 * there or its id is free (the database never hands an AUTO_INCREMENT id
	 * out twice), then the rows the change added removed. Where an id of the
	 * snapshot is taken by some other row, the values go back in their order
	 * into the rows the key has now, oldest first, new rows behind them.
	 *
	 * @param string $type post, term or user.
	 * @param int    $id   Object id.
	 * @param string $key  Meta key.
	 * @param array  $rows Snapshot rows { meta_id, value }, oldest first.
	 * @param array  $now  Rows of the key now (rows()).
	 * @return bool
	 */
	private static function restore_rows( $type, $id, $key, array $rows, array $now ) {
		global $wpdb;
		$t     = self::TABLES[ $type ];
		$table = $wpdb->{$t['table']};
		$by_id = array();
		foreach ( $now as $row ) {
			$by_id[ (int) $row['meta_id'] ] = $row;
		}
		$own = true;
		foreach ( $rows as $row ) {
			$mid = (int) $row['meta_id'];
			if ( ! isset( $by_id[ $mid ] ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT {$t['pk']} FROM {$table} WHERE {$t['pk']} = %d", $mid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$own = false;
				break;
			}
		}
		if ( $own ) {
			$keep = array();
			foreach ( $rows as $row ) {
				$mid          = (int) $row['meta_id'];
				$keep[ $mid ] = true;
				if ( isset( $by_id[ $mid ] ) ) {
					if ( $by_id[ $mid ]['value'] === $row['value'] ) {
						continue;
					}
					$done = $wpdb->update( $table, array( 'meta_value' => $row['value'] ), array( $t['pk'] => $mid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				} else {
					$done = $wpdb->insert( $table, array( $t['pk'] => $mid, $t['col'] => (int) $id, 'meta_key' => $key, 'meta_value' => $row['value'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				}
				if ( false === $done ) {
					return false;
				}
			}
			// Rows of the key the snapshot does not have came with the change.
			foreach ( $now as $row ) {
				if ( ! isset( $keep[ (int) $row['meta_id'] ] ) ) {
					$wpdb->delete( $table, array( $t['pk'] => (int) $row['meta_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			return true;
		}
		$slots = array_values( $now );
		foreach ( array_values( $rows ) as $i => $row ) {
			if ( isset( $slots[ $i ] ) ) {
				if ( $slots[ $i ]['value'] === $row['value'] ) {
					continue;
				}
				$done = $wpdb->update( $table, array( 'meta_value' => $row['value'] ), array( $t['pk'] => (int) $slots[ $i ]['meta_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			} else {
				$done = $wpdb->insert( $table, array( $t['col'] => (int) $id, 'meta_key' => $key, 'meta_value' => $row['value'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			}
			if ( false === $done ) {
				return false;
			}
		}
		for ( $i = count( $rows ); $i < count( $slots ); $i++ ) {
			$wpdb->delete( $table, array( $t['pk'] => (int) $slots[ $i ]['meta_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return true;
	}

	/**
	 * Why the account may not put this point back now, '' when it may: the
	 * object is gone, the account lacks the right the tool asks, or the tool
	 * would refuse the key now.
	 *
	 * @param string $tool Tool of the point.
	 * @param array  $snap Snapshot (valid()).
	 * @return string gone, forbidden or protected_now; '' when allowed.
	 */
	public static function refusal( $tool, array $snap ) {
		$id   = (int) $snap['id'];
		$keys = array_map( 'strval', array_keys( (array) $snap['keys'] ) );
		switch ( (string) $snap['type'] ) {
			case 'post':
				wp_cache_delete( $id, 'posts' );
				$post = get_post( $id );
				if ( ! $post || ( 'wp_update_media' === $tool && 'attachment' !== $post->post_type ) ) {
					return 'gone';
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return 'forbidden';
				}
				if ( 'wp_update_media' === $tool ) {
					// valid() let only the alternative text through.
					return '';
				}
				foreach ( $keys as $key ) {
					if ( self::rules( 'key_refused', $key ) ) {
						return 'protected_now';
					}
					if ( ! current_user_can( 'edit_post_meta', $id, $key ) || ( class_exists( 'AB_MCP_Builders' ) && null !== AB_MCP_Builders::markup_meta_refusal( $key, $post ) ) ) {
						return 'forbidden';
					}
				}
				return '';
			case 'term':
				$term = get_term( $id );
				if ( ! $term || is_wp_error( $term ) ) {
					return 'gone';
				}
				foreach ( $keys as $key ) {
					if ( self::rules( 'key_refused', $key ) ) {
						return 'protected_now';
					}
					if ( ! current_user_can( 'edit_term_meta', $id, $key ) ) {
						return 'forbidden';
					}
				}
				return '';
			case 'user':
				if ( ! get_user_by( 'id', $id ) ) {
					return 'gone';
				}
				if ( ! current_user_can( 'edit_user', $id ) ) {
					return 'forbidden';
				}
				foreach ( $keys as $key ) {
					if ( self::rules( 'user_key_refused', $key ) ) {
						return 'protected_now';
					}
					if ( ! current_user_can( 'edit_user_meta', $id, $key ) ) {
						return 'forbidden';
					}
				}
				return '';
		}
		return 'gone';
	}

	/**
	 * Ask a key rule of the meta tools (AB_MCP_Undo_Meta_Rules).
	 *
	 * @param string $rule key_refused or user_key_refused.
	 * @param string $key  Meta key.
	 * @return bool
	 */
	private static function rules( $rule, $key ) {
		if ( ! class_exists( 'AB_MCP_Undo_Meta_Rules', false ) ) {
			require_once __DIR__ . '/class-undo-meta-rules.php';
		}
		return (bool) call_user_func( array( 'AB_MCP_Undo_Meta_Rules', $rule ), (string) $key );
	}

	/**
	 * The ref of a point's item: the post id, as the page caches read it, or
	 * "term:<id>" and "user:<id>".
	 *
	 * @param array $snap Snapshot.
	 * @return int|string
	 */
	public static function ref( array $snap ) {
		return 'post' === $snap['type'] ? (int) $snap['id'] : $snap['type'] . ':' . (int) $snap['id'];
	}

	/* ------------------------------------------------------------ the form */

	/**
	 * Does the snapshot have the form the tool writes? Fail closed: a
	 * hand-edited point is refused whole, not partly applied.
	 *
	 * @param string $tool Tool of the point.
	 * @param mixed  $data Snapshot.
	 * @return bool
	 */
	public static function valid( $tool, $data ) {
		if ( ! isset( self::TOOLS[ $tool ] ) || ! is_array( $data ) || ! isset( $data['v'], $data['type'], $data['id'], $data['keys'], $data['after'] ) ) {
			return false;
		}
		if ( self::VERSION !== $data['v'] || self::TOOLS[ $tool ] !== $data['type'] || ! is_array( $data['keys'] ) || ! is_array( $data['after'] ) ) {
			return false;
		}
		if ( ! ( is_int( $data['id'] ) || ( is_string( $data['id'] ) && 1 === preg_match( '/\A[1-9][0-9]*\z/', $data['id'] ) ) ) || (int) $data['id'] <= 0 ) {
			return false;
		}
		$media  = 'wp_update_media' === $tool;
		$fields = array_key_exists( 'fields', $data ) ? $data['fields'] : array();
		if ( ! is_array( $fields ) || ( ! $media && array() !== $fields ) ) {
			return false;
		}
		// One key per point for the meta tools; the alternative text and the
		// two columns for wp_update_media, at least one of them.
		if ( $media ) {
			if ( array() === $data['keys'] && array() === $fields ) {
				return false;
			}
			if ( array() !== array_diff( array_map( 'strval', array_keys( $data['keys'] ) ), array( self::MEDIA_ALT ) ) ) {
				return false;
			}
		} elseif ( 1 !== count( $data['keys'] ) ) {
			return false;
		}
		$locs = array();
		foreach ( $data['keys'] as $key => $state ) {
			// A key of digits comes back from JSON as a number.
			$key = is_int( $key ) ? (string) $key : $key;
			if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 255 || ! self::valid_state( $state ) ) {
				return false;
			}
			$locs[] = 'meta:' . $key;
		}
		foreach ( $fields as $col => $value ) {
			if ( ! in_array( $col, self::MEDIA_FIELDS, true ) || ! is_string( $value ) ) {
				return false;
			}
			$locs[] = 'field:' . $col;
		}
		// The state after the change, for exactly these keys and columns.
		$after = $data['after'];
		if ( count( $after ) !== count( $locs ) ) {
			return false;
		}
		foreach ( $locs as $loc ) {
			if ( ! isset( $after[ $loc ] ) || ! is_string( $after[ $loc ] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $after[ $loc ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A key's state as encode_state() writes it.
	 *
	 * @param mixed $state State.
	 * @return bool
	 */
	private static function valid_state( $state ) {
		if ( ! is_array( $state ) || ! isset( $state['existed'] ) || ! is_bool( $state['existed'] ) ) {
			return false;
		}
		if ( ! $state['existed'] ) {
			return ! array_key_exists( 'rows', $state );
		}
		if ( ! isset( $state['rows'] ) || ! is_array( $state['rows'] ) || array() === $state['rows'] || array_values( $state['rows'] ) !== $state['rows'] ) {
			return false;
		}
		$seen = array();
		foreach ( $state['rows'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['meta_id'] ) || ! is_int( $row['meta_id'] ) || $row['meta_id'] <= 0 || isset( $seen[ $row['meta_id'] ] ) ) {
				return false;
			}
			$seen[ $row['meta_id'] ] = true;
			$has_value               = array_key_exists( 'value', $row );
			$has_b64                 = array_key_exists( 'b64', $row );
			if ( $has_value === $has_b64 || ( $has_value && ! is_string( $row['value'] ) )
				|| ( $has_b64 && ( ! is_string( $row['b64'] ) || false === base64_decode( $row['b64'], true ) ) ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- bytes JSON cannot carry.
				return false;
			}
		}
		return true;
	}

	/**
	 * Rows as rows() reads them, in the form a snapshot keeps them.
	 *
	 * @param array $rows rows().
	 * @return array
	 */
	private static function encode_state( array $rows ) {
		if ( array() === $rows ) {
			return array( 'existed' => false );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array_merge( array( 'meta_id' => (int) $row['meta_id'] ), self::encode_value( (string) $row['value'] ) );
		}
		return array(
			'existed' => true,
			'rows'    => $out,
		);
	}

	/**
	 * A raw value in the form a snapshot keeps it: as text where it is valid
	 * UTF-8, else base64 — JSON cannot carry other bytes, and wp_json_encode()
	 * would re-encode them into other ones rather than fail.
	 *
	 * @param string $value Raw bytes.
	 * @return array { value } or { b64 }.
	 */
	private static function encode_value( $value ) {
		$value = (string) $value;
		if ( 1 === preg_match( '//u', $value ) ) {
			return array( 'value' => $value );
		}
		return array( 'b64' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- bytes JSON cannot carry.
	}

	/**
	 * The raw bytes of a { value } or { b64 } holder.
	 *
	 * @param array $v Holder.
	 * @return string
	 */
	private static function raw_value( array $v ) {
		if ( array_key_exists( 'b64', $v ) ) {
			$raw = base64_decode( (string) $v['b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- bytes JSON cannot carry.
			return false === $raw ? '' : $raw;
		}
		return isset( $v['value'] ) ? (string) $v['value'] : '';
	}

	/**
	 * The answer to a key the database did not take back.
	 *
	 * @param string $key Meta key.
	 * @return WP_Error
	 */
	private static function failed( $key ) {
		return new WP_Error(
			'ab_mcp_undo_write_failed',
			sprintf(
				/* translators: %s: meta key */
				__( 'The database did not take back %s; the undo point stays. Try again; the reading tool of its object (wp_get_post_meta, wp_get_term_meta, wp_get_user_meta or wp_get_media) shows how it is now.', 'alphabridge-mcp' ),
				(string) $key
			)
		);
	}

	/**
	 * The answer to a snapshot in a form this class does not write.
	 *
	 * @return WP_Error
	 */
	private static function corrupt() {
		return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot of the meta is not in a form this tool writes; nothing was restored. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}
}
