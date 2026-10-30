<?php
/**
 * Undo kind term (since 4.13.0): a term wp_create_term or wp_create_menu
 * created (a menu is a term of nav_menu), deleted again, and the name, slug,
 * description and parent of a term wp_update_term changed, put back.
 *
 * Snapshot (one point, one term):
 *   created: { "v": 1, "id": int, "taxonomy": string,
 *              "after": { "term": sha256, "use": sha256 } }
 *   updated: { "v": 1, "id": int, "taxonomy": string,
 *              "fields": { "name", "slug", "description", "parent": string },
 *              "after": { "term": sha256 } }
 * "term" is the fields as the tables hold them; "use" what hangs on a new
 * term: the objects it is assigned to (for a menu: its items), its child
 * terms, its meta rows and, for a menu, the theme locations it is in. The
 * undo of a created term deletes it only while both are as the change left
 * them, and refuses a term renamed or put to use since unless force is set;
 * a term deleted since is nothing to do. The fields go back through
 * wp_update_term(), with its sanitising, and are read again from the
 * tables; the rights of the tool (the taxonomy's right to edit or delete
 * terms, edit_term or delete_term for this one) and the default-term rule
 * are asked again on the term the database holds under the id and the
 * exact taxonomy of the point.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-undo-objects.php';

/**
 * Class AB_MCP_Undo_Terms
 */
class AB_MCP_Undo_Terms extends AB_MCP_Undo_Objects {

	/** The undo kind. */
	const KIND = 'term';

	/** The fields wp_update_term writes, in the order they are hashed. */
	const FIELDS = array( 'name', 'slug', 'description', 'parent' );

	/** The tools that record this kind, and what they did to the term. */
	const TOOLS = array(
		'wp_create_term' => 'created',
		'wp_update_term' => 'updated',
		'wp_create_menu' => 'created',
	);

	/* ------------------------------------------------------------- the tables */

	/**
	 * The term as the tables hold it under this id and exactly this
	 * taxonomy: its fields as text and its term_taxonomy_id; false when there
	 * is none, null when the tables could not be read. The taxonomy is
	 * compared here, byte for byte, not by the collation of the database.
	 *
	 * @param int    $id       Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return array|false|null
	 */
	public static function row( $id, $taxonomy ) {
		global $wpdb;
		if ( ! self::has_tables( array( 'terms', 'term_taxonomy' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id WHERE t.term_id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		foreach ( $rows as $r ) {
			if ( isset( $r['taxonomy'] ) && (string) $r['taxonomy'] === (string) $taxonomy ) {
				return array(
					'name'        => (string) ( $r['name'] ?? '' ),
					'slug'        => (string) ( $r['slug'] ?? '' ),
					'description' => (string) ( $r['description'] ?? '' ),
					'parent'      => (string) (int) ( $r['parent'] ?? 0 ),
					'tt_id'       => (int) ( $r['term_taxonomy_id'] ?? 0 ),
				);
			}
		}
		return false;
	}

	/**
	 * What hangs on a term: the objects it is assigned to, its child terms,
	 * its meta rows, and for a menu the theme locations it is in. null when
	 * the tables could not be read.
	 *
	 * @param int    $id       Term id.
	 * @param int    $tt_id    Its term_taxonomy_id.
	 * @param string $taxonomy Taxonomy.
	 * @return array<string,int>|null
	 */
	public static function usage( $id, $tt_id, $taxonomy ) {
		global $wpdb;
		if ( ! self::has_tables( array( 'term_relationships', 'term_taxonomy', 'termmeta' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d) AS objects, (SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE parent = %d AND taxonomy = %s) AS children, (SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d) AS meta", (int) $tt_id, (int) $id, (string) $taxonomy, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb.
		if ( null === $rows || ! isset( $rows[0] ) ) {
			return null;
		}
		$out = array(
			'objects'  => (int) ( $rows[0]['objects'] ?? 0 ),
			'children' => (int) ( $rows[0]['children'] ?? 0 ),
			'meta'     => (int) ( $rows[0]['meta'] ?? 0 ),
		);
		if ( 'nav_menu' === $taxonomy && function_exists( 'get_nav_menu_locations' ) ) {
			$out['locations'] = count( array_keys( array_map( 'intval', (array) get_nav_menu_locations() ), (int) $id, true ) );
		}
		return $out;
	}

	/**
	 * The fields of row() in the order they are hashed.
	 *
	 * @param array $row row() or the fields of a snapshot.
	 * @return array<string,string>
	 */
	private static function fields_of( array $row ) {
		$out = array();
		foreach ( self::FIELDS as $f ) {
			$out[ $f ] = isset( $row[ $f ] ) ? (string) $row[ $f ] : '';
		}
		return $out;
	}

	/* --------------------------------------------------------------- hashes */

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>|null
	 */
	public static function now_hashes( array $snap ) {
		$row = self::row( (int) $snap['id'], (string) $snap['taxonomy'] );
		if ( null === $row ) {
			return null;
		}
		if ( isset( $snap['fields'] ) ) {
			return array( 'term' => false === $row ? self::ABSENT : self::hash( self::fields_of( $row ) ) );
		}
		if ( false === $row ) {
			return array(
				'term' => self::ABSENT,
				'use'  => self::ABSENT,
			);
		}
		$use = self::usage( (int) $snap['id'], $row['tt_id'], (string) $snap['taxonomy'] );
		if ( null === $use ) {
			return null;
		}
		return array(
			'term' => self::hash( self::fields_of( $row ) ),
			'use'  => self::hash( $use ),
		);
	}

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>
	 */
	public static function before_hashes( array $snap ) {
		if ( isset( $snap['fields'] ) ) {
			return array( 'term' => self::hash( self::fields_of( (array) $snap['fields'] ) ) );
		}
		return array(
			'term' => self::ABSENT,
			'use'  => self::ABSENT,
		);
	}

	/**
	 * @param array $snap Snapshot.
	 * @return string
	 */
	public static function ref( array $snap ) {
		return 'term:' . (int) $snap['id'];
	}

	/* ------------------------------------------------------------ recording */

	/**
	 * The undo and read-back part of the answer of a tool that created a
	 * term: the point deletes it again.
	 *
	 * @param string   $tool    wp_create_term or wp_create_menu.
	 * @param int      $id      The new term.
	 * @param string   $tax     Its taxonomy.
	 * @param array    $asked   field => value as sent (name, slug, description, parent).
	 * @param string   $reader  The tool that shows it.
	 * @param string   $summary Summary for wp_undo_list.
	 * @return array { saved_fields, saved_differs?, warning?, undo, undo_note? }
	 */
	public static function created( $tool, $id, $tax, array $asked, $reader, $summary ) {
		$snap = null;
		if ( self::on() ) {
			$data  = array(
				'v'        => self::VERSION,
				'id'       => (int) $id,
				'taxonomy' => (string) $tax,
			);
			$after = self::now_hashes( $data );
			if ( null !== $after && self::ABSENT !== $after['term'] ) {
				$data['after'] = $after;
				$snap          = $data;
			}
		}
		$outcome = self::outcome( $tool, $reader, true, $snap, $summary );
		$res     = AB_MCP_Undo_Answer::saved( array(), $asked, self::read_back( $id, $tax ), $reader );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * Write through wp_update_term and answer for it: the fields before, the
	 * write, the read-back, the undo point. As AB_MCP_Undo_Meta::write(): a
	 * write that changed nothing while the term does not hold what was asked
	 * — not even in the form WordPress stores it in — is an error.
	 *
	 * @param array    $spec  { tool, id, taxonomy, asked: field => value, reader, summary }.
	 * @param callable $write Does the write; a WP_Error is passed on as it is.
	 * @return array|WP_Error
	 */
	public static function update( array $spec, callable $write ) {
		$tool   = (string) $spec['tool'];
		$id     = (int) $spec['id'];
		$tax    = (string) $spec['taxonomy'];
		$asked  = (array) $spec['asked'];
		$reader = (string) $spec['reader'];

		$snap = null;
		if ( self::on() ) {
			$row = self::row( $id, $tax );
			if ( is_array( $row ) ) {
				$snap = array(
					'v'        => self::VERSION,
					'id'       => $id,
					'taxonomy' => $tax,
					'fields'   => self::fields_of( $row ),
				);
			}
		}
		$before = self::read_back( $id, $tax );
		$done   = call_user_func( $write );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$after = self::read_back( $id, $tax );

		$hashes  = null !== $snap ? self::now_hashes( $snap ) : null;
		$changed = null !== $hashes ? $hashes !== self::before_hashes( $snap ) : $after !== $before;
		if ( ! $changed ) {
			$refused = array();
			foreach ( $asked as $f => $want ) {
				$have = array_key_exists( $f, $after ) ? $after[ $f ] : null;
				if ( $have !== $want && $have !== self::expected( (string) $f, $want, $id, $tax ) ) {
					$refused[] = (string) $f;
				}
			}
			if ( array() !== $refused ) {
				return new WP_Error(
					'ab_mcp_not_saved',
					sprintf(
						/* translators: 1: comma-separated field names, 2: tool name */
						__( 'WordPress did not store the change: %1$s of the term still hold what they held before. A plugin may refuse it on save (a filter on the term update). Check the term with %2$s, and ask the site administrator which plugin guards it.', 'alphabridge-mcp' ),
						implode( ', ', $refused ),
						$reader
					)
				);
			}
		}
		if ( null !== $snap && null !== $hashes ) {
			$snap['after'] = $hashes;
		} else {
			$snap = null;
		}
		$outcome = self::outcome( $tool, $reader, $changed, $snap, (string) $spec['summary'] );
		$res     = AB_MCP_Undo_Answer::saved( array(), $asked, $after, $reader );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * A field as WordPress itself stores what was sent: the name and the
	 * description through the term's own sanitising (sanitize_term_field()
	 * in the db context), the slug as sanitize_title() makes it. A value that
	 * comes back in this form was stored, though not byte for byte.
	 *
	 * @param string $field Field.
	 * @param mixed  $value As sent.
	 * @param int    $id    Term id.
	 * @param string $tax   Taxonomy.
	 * @return mixed
	 */
	private static function expected( $field, $value, $id, $tax ) {
		if ( ( 'name' === $field || 'description' === $field ) && function_exists( 'sanitize_term_field' ) ) {
			return wp_unslash( sanitize_term_field( $field, wp_slash( (string) $value ), $id, $tax, 'db' ) );
		}
		if ( 'slug' === $field && function_exists( 'sanitize_title' ) ) {
			return sanitize_title( (string) $value );
		}
		return $value;
	}

	/**
	 * The term as wp_get_term shows it, read again past the term cache:
	 * name, slug, description and parent; empty when it is not there.
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 * @return array
	 */
	public static function read_back( $id, $tax ) {
		if ( function_exists( 'clean_term_cache' ) ) {
			clean_term_cache( (int) $id, (string) $tax );
		}
		$term = get_term( (int) $id, (string) $tax );
		if ( ! is_object( $term ) || is_wp_error( $term ) ) {
			return array();
		}
		return array(
			'name'        => isset( $term->name ) && is_scalar( $term->name ) ? (string) $term->name : '',
			'slug'        => isset( $term->slug ) && is_scalar( $term->slug ) ? (string) $term->slug : '',
			'description' => isset( $term->description ) && is_scalar( $term->description ) ? (string) $term->description : '',
			'parent'      => isset( $term->parent ) && is_scalar( $term->parent ) ? (int) $term->parent : 0,
		);
	}

	/* ------------------------------------------------------------- restore */

	/**
	 * @param string $tool Tool of the point.
	 * @param array  $snap Snapshot (valid()).
	 * @return string
	 */
	public static function refusal( $tool, array $snap ) {
		$id  = (int) $snap['id'];
		$tax = (string) $snap['taxonomy'];
		if ( ! taxonomy_exists( $tax ) ) {
			return 'gone';
		}
		$obj = get_taxonomy( $tax );
		$row = self::row( $id, $tax );
		if ( ! $obj || ! is_array( $row ) ) {
			return 'gone';
		}
		if ( isset( $snap['fields'] ) ) {
			return current_user_can( $obj->cap->edit_terms ) && current_user_can( 'edit_term', $id ) ? '' : 'forbidden';
		}
		// The taxonomy's default term stays, as wp_delete_term keeps it.
		if ( (int) get_option( 'default_' . $tax ) === $id || (int) get_option( 'default_term_' . $tax ) === $id ) {
			return 'protected_now';
		}
		return current_user_can( $obj->cap->delete_terms ) && current_user_can( 'delete_term', $id ) ? '' : 'forbidden';
	}

	/**
	 * @param array    $snap Snapshot.
	 * @param string[] $locs Locations that differ.
	 * @return true|WP_Error
	 */
	protected static function put_back( array $snap, array $locs ) {
		$id  = (int) $snap['id'];
		$tax = (string) $snap['taxonomy'];
		if ( ! isset( $snap['fields'] ) ) {
			// A menu goes with its items and its theme locations, as wp-admin deletes it.
			$done = ( 'nav_menu' === $tax && function_exists( 'wp_delete_nav_menu' ) ) ? wp_delete_nav_menu( $id ) : wp_delete_term( $id, $tax );
			if ( is_wp_error( $done ) || ! $done || false !== self::row( $id, $tax ) ) {
				return new WP_Error(
					'ab_mcp_undo_write_failed',
					sprintf(
						/* translators: 1: term id, 2: what WordPress said, or empty */
						__( 'WordPress did not delete term %1$d again%2$s; the undo point stays. Check it with wp_get_term, and try again.', 'alphabridge-mcp' ),
						$id,
						is_wp_error( $done ) ? ' (' . $done->get_error_message() . ')' : ''
					)
				);
			}
			return true;
		}
		$f    = (array) $snap['fields'];
		$done = wp_update_term(
			$id,
			$tax,
			array(
				// wp_update_term() unslashes name and description, not slug.
				'name'        => wp_slash( (string) $f['name'] ),
				'slug'        => (string) $f['slug'],
				'description' => wp_slash( (string) $f['description'] ),
				'parent'      => (int) $f['parent'],
			)
		);
		if ( is_wp_error( $done ) ) {
			return new WP_Error(
				'ab_mcp_undo_write_failed',
				sprintf(
					/* translators: %s: what WordPress said */
					__( 'WordPress did not take the old fields of the term back (%s); the undo point stays. Check the term with wp_get_term: a slug taken since or a parent deleted since is changed there first.', 'alphabridge-mcp' ),
					$done->get_error_message()
				)
			);
		}
		if ( function_exists( 'clean_term_cache' ) ) {
			clean_term_cache( $id, $tax );
		}
		$row = self::row( $id, $tax );
		$off = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! is_array( $row ) || $row[ $field ] !== (string) $f[ $field ] ) {
				$off[] = $field;
			}
		}
		if ( array() !== $off ) {
			return new WP_Error(
				'ab_mcp_undo_term_mismatch',
				sprintf(
					/* translators: %s: comma-separated field names */
					__( 'The term was written back, but the database holds something else for: %s. A plugin may rewrite term fields on save; check the term with wp_get_term.', 'alphabridge-mcp' ),
					implode( ', ', $off )
				)
			);
		}
		return true;
	}

	/**
	 * @return WP_Error
	 */
	protected static function changed_error() {
		return new WP_Error( 'ab_mcp_undo_term_changed', __( 'The term was changed or put to use after the undo was planned; nothing was restored. Pass force=true to restore it over the newer change.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error
	 */
	protected static function corrupt() {
		return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot of the term is not in a form this tool writes; nothing was restored. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------------------------------- the form */

	/**
	 * @param string $tool Tool of the point.
	 * @param mixed  $data Snapshot.
	 * @return bool
	 */
	public static function valid( $tool, $data ) {
		if ( ! isset( self::TOOLS[ $tool ] ) || ! is_array( $data ) || ! isset( $data['v'], $data['id'], $data['taxonomy'], $data['after'] ) ) {
			return false;
		}
		if ( self::VERSION !== $data['v'] || ! is_int( $data['id'] ) || $data['id'] <= 0 || ! is_string( $data['taxonomy'] ) || '' === $data['taxonomy'] || strlen( $data['taxonomy'] ) > 32 || ! is_array( $data['after'] ) ) {
			return false;
		}
		// A menu is a term of nav_menu and nothing else.
		if ( 'wp_create_menu' === $tool && 'nav_menu' !== $data['taxonomy'] ) {
			return false;
		}
		$keys = array_keys( $data );
		sort( $keys );
		if ( 'updated' === self::TOOLS[ $tool ] ) {
			if ( array( 'after', 'fields', 'id', 'taxonomy', 'v' ) !== $keys || ! is_array( $data['fields'] ) ) {
				return false;
			}
			$fields = array_keys( $data['fields'] );
			if ( self::FIELDS !== $fields ) {
				return false;
			}
			foreach ( $data['fields'] as $f => $value ) {
				if ( ! is_string( $value ) || ( 'parent' === $f && 1 !== preg_match( '/\A(0|[1-9][0-9]*)\z/', $value ) ) ) {
					return false;
				}
			}
			$locs = array( 'term' );
		} else {
			if ( array( 'after', 'id', 'taxonomy', 'v' ) !== $keys ) {
				return false;
			}
			$locs = array( 'term', 'use' );
		}
		$after = array_keys( $data['after'] );
		sort( $after );
		if ( $locs !== $after ) {
			return false;
		}
		foreach ( $data['after'] as $sha ) {
			if ( ! is_string( $sha ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $sha ) ) {
				return false;
			}
		}
		return true;
	}
}
