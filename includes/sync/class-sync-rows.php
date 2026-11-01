<?php
/**
 * The rows the takeover compares, read straight from the tables (Z8,
 * decision 11 of the plan of 01.11.2026): posts, postmeta, terms,
 * term_taxonomy, termmeta, term_relationships, options and the login of a
 * user. Never through get_post(), get_post_meta(), get_term() or
 * get_option(): filters replace values there, and the object cache may hold
 * another state than the table (the lesson of Z7).
 *
 * THREE ANSWERS, as the undo readers give them (AB_MCP_Undo_Objects::select(),
 * AB_MCP_Undo_Meta::rows()): the rows; none (false for one row, an empty list
 * for many); null when the question could not be asked. query() and
 * last_result, not get_row() or get_results(): a SELECT that ran answers with
 * its count, 0 included, and false means it was never made. A caller treats
 * null as "not read", never as "not there".
 *
 * Built on the undo readers where they fit: an option row by its exact name
 * is AB_MCP_Undo_Widgets::row() (a row of another spelling the collation
 * matched is not that row), the rows of one meta key AB_MCP_Undo_Meta::rows().
 * What those do not read — every column of a post, every meta row of an
 * object, the terms of an object, pages of ids for the inventory, a term with
 * its term_group — is read here in the same way. Values come back as the
 * bytes the table holds.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/undo/class-undo-meta.php';
require_once dirname( __DIR__ ) . '/undo/class-undo-widgets.php';

/**
 * Class AB_MCP_Sync_Rows
 */
final class AB_MCP_Sync_Rows {

	/** Every column of the posts table of WordPress 7.1.3 (wp-admin/includes/schema.php). */
	const POST_COLUMNS = array( 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' );

	/** The columns of a term as terms and term_taxonomy hold them. */
	const TERM_COLUMNS = array( 'term_id', 'name', 'slug', 'term_group', 'term_taxonomy_id', 'taxonomy', 'description', 'parent', 'count' );

	/** Rows per page of the inventory readers at most. */
	const MAX_PAGE = 500;

	/* ------------------------------------------------------------ posts */

	/**
	 * Every column of one post, as stored.
	 *
	 * @param int $id Post id.
	 * @return array<string,string>|false|null
	 */
	public static function post( $id ) {
		global $wpdb;
		if ( ! self::has( array( 'posts' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( 'SELECT ' . implode( ', ', self::POST_COLUMNS ) . " FROM {$wpdb->posts} WHERE ID = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns from POST_COLUMNS, table from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		return isset( $rows[0] ) ? self::columns( $rows[0], self::POST_COLUMNS ) : false;
	}

	/**
	 * The posts of some types after an id, oldest id first: the pages of the
	 * inventory.
	 *
	 * @param string[]|null $types Post types, compared byte for byte; null
	 *                             for every type.
	 * @param int           $after Id the page before ended with; 0 at the start.
	 * @param int           $limit Rows at most (MAX_PAGE).
	 * @return array<int,array{ID:int,post_type:string}>|null
	 */
	public static function post_ids( $types, $after, $limit ) {
		global $wpdb;
		if ( is_array( $types ) ) {
			$types = array_values( array_unique( array_map( 'strval', $types ) ) );
			if ( array() === $types ) {
				return array();
			}
		}
		if ( ! self::has( array( 'posts' ) ) ) {
			return null;
		}
		$in   = is_array( $types ) ? ' AND post_type IN ( ' . implode( ', ', array_fill( 0, count( $types ), 'BINARY %s' ) ) . ' )' : '';
		$args = array_merge( array( (int) $after ), is_array( $types ) ? $types : array(), array( self::limit( $limit ) ) );
		$rows = self::select( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID > %d{$in} ORDER BY ID ASC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'ID'        => (int) $row['ID'],
				'post_type' => (string) $row['post_type'],
			);
		}
		return $out;
	}

	/**
	 * Every meta row of an object, by meta_id: the key and the value as
	 * stored. Every key is in it, also one stored in another letter case
	 * than another (meta_key compares by a collation; here each row keeps
	 * its own bytes).
	 *
	 * @param string $type post or term (AB_MCP_Undo_Meta::TABLES).
	 * @param int    $id   Object id.
	 * @return array<int,array{meta_id:int,key:string,value:string}>|null
	 */
	public static function meta( $type, $id ) {
		global $wpdb;
		if ( ! in_array( $type, array( 'post', 'term' ), true ) ) {
			return null;
		}
		$t = AB_MCP_Undo_Meta::TABLES[ $type ];
		if ( ! self::has( array( $t['table'] ) ) ) {
			return null;
		}
		$table = (string) $wpdb->{$t['table']};
		$rows  = self::select( $wpdb->prepare( "SELECT {$t['pk']} AS meta_id, meta_key, meta_value FROM {$table} WHERE {$t['col']} = %d ORDER BY {$t['pk']} ASC", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and columns from AB_MCP_Undo_Meta::TABLES.
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'meta_id' => (int) $row['meta_id'],
				'key'     => (string) $row['meta_key'],
				'value'   => null === $row['meta_value'] ? '' : (string) $row['meta_value'],
			);
		}
		return $out;
	}

	/**
	 * The rows of one meta key, by meta_id (AB_MCP_Undo_Meta::rows()), only
	 * those stored under exactly these bytes.
	 *
	 * @param string $type post or term.
	 * @param int    $id   Object id.
	 * @param string $key  Meta key.
	 * @return string[]|null The values.
	 */
	public static function meta_values( $type, $id, $key ) {
		$rows = AB_MCP_Undo_Meta::rows( $type, (int) $id, (string) $key );
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( (string) $row['key'] === (string) $key ) {
				$out[] = (string) $row['value'];
			}
		}
		return $out;
	}

	/**
	 * The terms an object is assigned to: each with its taxonomy, term id,
	 * slug and term_order, by term_taxonomy_id.
	 *
	 * @param int $object_id Object id.
	 * @return array<int,array{term_id:int,taxonomy:string,slug:string,term_order:int}>|null
	 */
	public static function object_terms( $object_id ) {
		global $wpdb;
		if ( ! self::has( array( 'term_relationships', 'term_taxonomy', 'terms' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT tr.term_taxonomy_id, tr.term_order, tt.term_id, tt.taxonomy, t.slug FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id WHERE tr.object_id = %d ORDER BY tr.term_taxonomy_id ASC", (int) $object_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'term_id'    => (int) $row['term_id'],
				'taxonomy'   => (string) $row['taxonomy'],
				'slug'       => (string) $row['slug'],
				'term_order' => (int) $row['term_order'],
			);
		}
		return $out;
	}

	/**
	 * The objects a term is assigned to, by object id.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Its taxonomy, compared byte for byte.
	 * @return int[]|null
	 */
	public static function term_objects( $term_id, $taxonomy ) {
		global $wpdb;
		if ( ! self::has( array( 'term_relationships', 'term_taxonomy' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT tr.object_id FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d AND tt.taxonomy = BINARY %s ORDER BY tr.object_id ASC", (int) $term_id, (string) $taxonomy ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		return array_map( 'intval', array_column( $rows, 'object_id' ) );
	}

	/* ------------------------------------------------------------ terms */

	/**
	 * One term under exactly this taxonomy: every column of TERM_COLUMNS.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return array<string,string>|false|null
	 */
	public static function term( $term_id, $taxonomy ) {
		global $wpdb;
		if ( ! self::has( array( 'terms', 'term_taxonomy' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = BINARY %s", (int) $term_id, (string) $taxonomy ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		return isset( $rows[0] ) ? self::columns( $rows[0], self::TERM_COLUMNS ) : false;
	}

	/**
	 * The taxonomies a term id has rows in (one, unless the core has not
	 * split a shared term).
	 *
	 * @param int $term_id Term id.
	 * @return string[]|null
	 */
	public static function term_taxonomies( $term_id ) {
		global $wpdb;
		if ( ! self::has( array( 'term_taxonomy' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d ORDER BY term_taxonomy_id ASC", (int) $term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
		return null === $rows ? null : array_map( 'strval', array_column( $rows, 'taxonomy' ) );
	}

	/**
	 * The terms of some taxonomies after a term_taxonomy_id, in that order.
	 *
	 * @param string[]|null $taxonomies Taxonomies, compared byte for byte;
	 *                                  null for every taxonomy.
	 * @param int           $after      term_taxonomy_id the page before ended with.
	 * @param int           $limit      Rows at most.
	 * @return array<int,array<string,string>>|null
	 */
	public static function terms( $taxonomies, $after, $limit ) {
		global $wpdb;
		if ( is_array( $taxonomies ) ) {
			$taxonomies = array_values( array_unique( array_map( 'strval', $taxonomies ) ) );
			if ( array() === $taxonomies ) {
				return array();
			}
		}
		if ( ! self::has( array( 'terms', 'term_taxonomy' ) ) ) {
			return null;
		}
		$in   = is_array( $taxonomies ) ? ' AND tt.taxonomy IN ( ' . implode( ', ', array_fill( 0, count( $taxonomies ), 'BINARY %s' ) ) . ' )' : '';
		$args = array_merge( array( (int) $after ), is_array( $taxonomies ) ? $taxonomies : array(), array( self::limit( $limit ) ) );
		$rows = self::select( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM {$wpdb->term_taxonomy} AS tt INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id WHERE tt.term_taxonomy_id > %d{$in} ORDER BY tt.term_taxonomy_id ASC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = self::columns( $row, self::TERM_COLUMNS );
		}
		return $out;
	}

	/* ---------------------------------------------------------- options */

	/**
	 * One option row by its exact name (AB_MCP_Undo_Widgets::row()): the
	 * value as stored and the autoload flag.
	 *
	 * @param string $name Option name.
	 * @return array{value:string,autoload:string}|false|null
	 */
	public static function option( $name ) {
		return AB_MCP_Undo_Widgets::row( (string) $name );
	}

	/**
	 * The option rows after an option_id, in that order: name, value,
	 * autoload.
	 *
	 * @param int $after option_id the page before ended with.
	 * @param int $limit Rows at most.
	 * @return array<int,array{option_id:int,name:string,value:string,autoload:string}>|null
	 */
	public static function options( $after, $limit ) {
		global $wpdb;
		if ( ! self::has( array( 'options' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_id > %d ORDER BY option_id ASC LIMIT %d", (int) $after, self::limit( $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'option_id' => (int) $row['option_id'],
				'name'      => (string) $row['option_name'],
				'value'     => null === $row['option_value'] ? '' : (string) $row['option_value'],
				'autoload'  => (string) $row['autoload'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------ users */

	/**
	 * The login of a user: the identity of an author on both sides.
	 *
	 * @param int $id User id.
	 * @return string|false|null
	 */
	public static function user_login( $id ) {
		global $wpdb;
		if ( ! self::has( array( 'users' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE ID = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		return isset( $rows[0]['user_login'] ) ? (string) $rows[0]['user_login'] : false;
	}

	/* ---------------------------------------------------------- private */

	/**
	 * Run one SELECT and hand back its rows as arrays; null when it could
	 * not run. The answers of AB_MCP_Undo_Objects::select().
	 *
	 * @param string $sql Prepared statement.
	 * @return array[]|null
	 */
	private static function select( $sql ) {
		global $wpdb;
		$ran = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared by the caller; the stored bytes are the point.
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
	private static function has( array $tables ) {
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
	 * @param array    $row     Row as read.
	 * @param string[] $columns Columns.
	 * @return array<string,string> Each column as text ('' for NULL).
	 */
	private static function columns( array $row, array $columns ) {
		$out = array();
		foreach ( $columns as $column ) {
			$out[ $column ] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
		}
		return $out;
	}

	/**
	 * @param int $limit Asked.
	 * @return int Between 1 and MAX_PAGE.
	 */
	private static function limit( $limit ) {
		return max( 1, min( self::MAX_PAGE, (int) $limit ) );
	}
}
