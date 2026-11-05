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
	 * Whether the charset of the site is UTF-8, from its row blog_charset
	 * (utf8, utf-8 in any case, as _wp_specialchars() takes it): the
	 * spellings the HTML of the site is read in. False where it is another
	 * or the row cannot be read.
	 *
	 * @return bool
	 */
	public static function utf8() {
		$row = self::option( 'blog_charset' );
		return is_array( $row ) && in_array( strtolower( trim( (string) $row['value'] ) ), array( 'utf-8', 'utf8' ), true );
	}

	/**
	 * The address of the uploads of this site, from its rows, as
	 * _wp_upload_dir() of the core makes it (wp-includes/functions.php
	 * 7.1.3, :2467-2496), its base only:
	 * - upload_path trimmed; empty ("" or "0") or wp-content/uploads: the
	 *   directory WP_CONTENT_DIR/uploads; not under ABSPATH: path_join(
	 *   ABSPATH, upload_path), which leaves an absolute path as it is
	 *   (path_is_absolute(): "/", "\" or a drive with "\" at its start);
	 *   under ABSPATH (absolute too): upload_path itself;
	 * - upload_url_path where it is set (not "" or "0"); else
	 *   WP_CONTENT_URL/uploads where upload_path is empty, wp-content/uploads
	 *   or the directory itself (absolute: upload_path=/srv/shared/uploads
	 *   gives …/wp-content/uploads); else siteurl/upload_path;
	 * - UPLOADS where it is defined: siteurl/UPLOADS;
	 * siteurl as get_option() gives it (WP_SITEURL where it is defined,
	 * _config_wp_siteurl()). Null where it is not known: a row not read, a
	 * row that holds no text (a serialized value), a stream as upload_path
	 * (whether it is absolute, only the file system tells), a network (the
	 * folder of each site).
	 *
	 * @return string|null Without a slash at its end.
	 */
	public static function uploads_url() {
		$rows = array();
		foreach ( array( 'siteurl', 'upload_path', 'upload_url_path' ) as $name ) {
			// A row that is not there is empty (get_option() gives false); one
			// not read, or one that holds no text, is not known.
			$row = self::option( $name );
			if ( null === $row || ( is_array( $row ) && is_serialized( (string) $row['value'] ) ) ) {
				return null;
			}
			$rows[ $name ] = is_array( $row ) ? (string) $row['value'] : '';
		}
		$siteurl = defined( 'WP_SITEURL' ) ? rtrim( (string) WP_SITEURL, '/\\' ) : $rows['siteurl'];
		$path    = trim( $rows['upload_path'] );
		if ( false !== strpos( $path, '://' ) || is_multisite() ) {
			return null;
		}
		// upload_path === $dir of _wp_upload_dir(): empty, wp-content/uploads,
		// or absolute (path_join() leaves it as it is; under ABSPATH is
		// absolute as well).
		$content_dir = '' === $path || '0' === $path || 'wp-content/uploads' === $path || '/' === $path[0] || '\\' === $path[0] || 1 === preg_match( '#^[a-zA-Z]:\\\\#', $path );
		$url = $rows['upload_url_path'];
		if ( '' === $url || '0' === $url ) {
			$content = defined( 'WP_CONTENT_URL' ) ? (string) WP_CONTENT_URL : $siteurl . '/wp-content';
			$url     = $content_dir ? $content . '/uploads' : rtrim( $siteurl, '/\\' ) . '/' . $path;
		}
		if ( defined( 'UPLOADS' ) ) {
			$url = rtrim( $siteurl, '/\\' ) . '/' . UPLOADS;
		}
		return rtrim( $url, '/' );
	}

	/**
	 * Whether the page of a path exists as WP::parse_request() takes it for
	 * a verbose rule of pages (AB_MCP_Sync_Urls::route()), from the rows,
	 * without the cache: the search of get_page_by_path() (the parts of the
	 * path as rawurlencode( urldecode() ) writes them, %2F a slash, %20 a
	 * space, in lower case as post_name holds an encoded slug; the pages and
	 * attachments of those names; the one whose names up its parents are the
	 * parts, from the last, a page before an attachment), then the status
	 * WP::parse_request() refuses (neither public, protected nor private,
	 * and excluded from search). A part sanitize_title_for_query() would
	 * write otherwise (a space, a dot, a capital letter in an escape …) is
	 * not known.
	 *
	 * @param string $path The path of the page, as the rule matched it.
	 * @return bool|null Null where it is not known.
	 */
	public static function page_path( $path ) {
		global $wpdb;
		if ( ! self::has( array( 'posts' ) ) || ! function_exists( 'get_post_status_object' ) ) {
			return null;
		}
		$path  = str_replace( array( '%2F', '%20' ), array( '/', ' ' ), rawurlencode( urldecode( (string) $path ) ) );
		$parts = array();
		foreach ( explode( '/', trim( $path, '/' ) ) as $part ) {
			$part = (string) preg_replace_callback(
				'/%[0-9A-F]{2}/',
				static function ( $m ) {
					return strtolower( $m[0] );
				},
				$part
			);
			if ( ! preg_match( '/^(?:[a-z0-9_-]|%[0-9a-f]{2})+\z/', $part ) ) {
				return null;
			}
			$parts[] = $part;
		}
		$rows = self::select( "SELECT ID, post_name, post_parent, post_type, post_status FROM {$wpdb->posts} WHERE post_name IN ('" . implode( "','", $parts ) . "') AND post_type IN ( 'page', 'attachment' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the parts hold only [a-z0-9_%-], table from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		$pages = array();
		foreach ( $rows as $row ) {
			$pages[ (int) $row['ID'] ] = $row;
		}
		$back  = array_reverse( $parts );
		$found = null;
		foreach ( $pages as $page ) {
			if ( $page['post_name'] !== $back[0] ) {
				continue;
			}
			$count = 0;
			$p     = $page;
			while ( 0 !== (int) $p['post_parent'] && isset( $pages[ (int) $p['post_parent'] ] ) ) {
				++$count;
				$parent = $pages[ (int) $p['post_parent'] ];
				if ( ! isset( $back[ $count ] ) || $parent['post_name'] !== $back[ $count ] ) {
					break;
				}
				$p = $parent;
			}
			if ( 0 === (int) $p['post_parent'] && count( $back ) === $count + 1 && $p['post_name'] === $back[ $count ] ) {
				$found = $page;
				if ( 'page' === $page['post_type'] ) {
					break;
				}
			}
		}
		if ( null === $found ) {
			return false;
		}
		$status = get_post_status_object( (string) $found['post_status'] );
		if ( null === $status ) {
			return null;
		}
		return ! ( ! $status->public && ! $status->protected && ! $status->private && $status->exclude_from_search );
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
	 * The posts of one type with exactly this slug under one parent, by id
	 * (Codex round 2, A-1: an identity that is not unique on its site names
	 * no target).
	 *
	 * @param string $type   Post type, compared byte for byte.
	 * @param string $name   post_name, compared byte for byte.
	 * @param int    $parent post_parent.
	 * @return int[]|null
	 */
	public static function posts_named( $type, $name, $parent ) {
		global $wpdb;
		if ( ! self::has( array( 'posts' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = BINARY %s AND post_name = BINARY %s AND post_parent = %d ORDER BY ID ASC", (string) $type, (string) $name, (int) $parent ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
		return null === $rows ? null : array_map( 'intval', array_column( $rows, 'ID' ) );
	}

	/**
	 * The attachments whose _wp_attached_file is exactly this file, by id.
	 *
	 * @param string $file The file under the uploads.
	 * @return int[]|null
	 */
	public static function attachments_of( $file ) {
		global $wpdb;
		if ( ! self::has( array( 'posts', 'postmeta' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} AS p INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.ID WHERE p.post_type = 'attachment' AND pm.meta_key = BINARY '_wp_attached_file' AND pm.meta_value = BINARY %s ORDER BY p.ID ASC", (string) $file ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb.
		return null === $rows ? null : array_map( 'intval', array_column( $rows, 'ID' ) );
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
	 * The terms of one taxonomy with exactly this slug, by term id (Codex
	 * round 2, A-1: an identity that is not unique on its site names no
	 * target).
	 *
	 * @param string $taxonomy Taxonomy, compared byte for byte.
	 * @param string $slug     Slug, compared byte for byte.
	 * @return int[]|null
	 */
	public static function terms_by_slug( $taxonomy, $slug ) {
		global $wpdb;
		if ( ! self::has( array( 'terms', 'term_taxonomy' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id WHERE tt.taxonomy = BINARY %s AND t.slug = BINARY %s ORDER BY t.term_id ASC", (string) $taxonomy, (string) $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb.
		return null === $rows ? null : array_map( 'intval', array_column( $rows, 'term_id' ) );
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
	 * The rows a page of the inventory readers really holds at most for an
	 * asked limit: a caller that ends its cursor on a short page compares
	 * with this, not with what it asked.
	 *
	 * @param int $limit Asked.
	 * @return int Between 1 and MAX_PAGE.
	 */
	public static function page_size( $limit ) {
		return max( 1, min( self::MAX_PAGE, (int) $limit ) );
	}

	/**
	 * @param int $limit Asked.
	 * @return int page_size().
	 */
	private static function limit( $limit ) {
		return self::page_size( $limit );
	}
}
