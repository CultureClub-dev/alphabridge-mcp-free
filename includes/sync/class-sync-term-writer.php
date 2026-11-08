<?php
/**
 * The write side of the kind term (contract A of the plan of 01.11.2026;
 * Z8 branch 9, decisions 17, 19, 23, 25 and 28): what a step of a run
 * writes for one term of category, post_tag or wp_pattern_category, how it
 * is backed up, read back and put back. The same methods as the kind post
 * (AB_MCP_Sync_Post_Writer), so the engine runs both alike
 * (AB_MCP_Sync_Engine::WRITERS).
 *
 * WHAT IS WRITTEN: the row of a term (name, slug, description and parent,
 * the parent by its pair; one the same run creates before it is pending,
 * «Eltern zuerst»), and on a term that is there its meta, one part per key
 * whose values differ between the sides, with the rights per key through
 * map_meta_cap (decision 28: edit_, add_ or delete_term_meta; a key the
 * map refuses locks the term, «Feldregel folgt mit Z8d»; a key equal on
 * both sides stays untouched). A new term with meta is locked
 * (term:create_with_meta, Z8d: the right to its meta can only be asked of
 * a term that exists, and a term made in a run is not taken back). No term
 * is deleted (decisions 21 and 22).
 *
 * THE WAY FORWARD (decision 17): wp_insert_term() and wp_update_term(),
 * slashed as they unslash; meta through update_metadata(), add_metadata()
 * and delete_metadata() with wp_slash(). Before the first part every value
 * goes through the sanitizers WordPress runs on a save, without writing
 * (sanitize_term( …, 'db' ) in the rights of this account, sanitize_meta()),
 * the slug through what WordPress would make of it (another term of the
 * taxonomy holding it, wp_unique_term_slug()), the parent through the loop
 * check WordPress runs on an update (wp_check_term_hierarchy_for_loops(),
 * which would change the parent of other terms); a deviation refuses with
 * its lock before anything is written.
 *
 * THE WAY BACK (decision 23): the row through wp_update_term() with the
 * backed-up columns, only where the row now hashes to what the step read
 * back and the backed-up slug and parent are free of the same checks; meta
 * rows raw, byte for byte conditional (AB_MCP_Sync_Post_Writer::
 * restore_meta() for the type term). A term the step made is never removed.
 *
 * THE WAYS of decision 19: the row of a term is written on a way the lock
 * does not refuse for another hand (wp_update_term() of another plugin or
 * of wp-admin's term screens has no filter; the REST API and new terms of
 * the taxonomy are refused, AB_MCP_Sync_Guard), its meta on a refusable
 * one.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-refs.php';
require_once __DIR__ . '/class-sync-kinds.php';
require_once __DIR__ . '/class-sync-db-kinds.php';
require_once __DIR__ . '/class-sync-credentials.php';
require_once __DIR__ . '/class-sync-post-writer.php';
require_once dirname( __DIR__ ) . '/class-redactor.php';

/**
 * Class AB_MCP_Sync_Term_Writer
 */
final class AB_MCP_Sync_Term_Writer {

	/** The taxonomies of the kind term (AB_MCP_Sync_Kinds::OWNERSHIP). */
	const TAXONOMIES = array( 'category', 'post_tag', 'wp_pattern_category' );

	/** The columns of a term row the writer compares and writes (term_group only as 0, term:term_group). */
	const WRITTEN_COLUMNS = array( 'name', 'slug', 'term_group', 'taxonomy', 'description', 'parent' );

	/** The columns of the row named where the row is no longer there to compare. */
	const NAMED_COLUMNS = array( 'name', 'slug', 'description', 'parent' );

	/** The parts of a step, by their way (decision 19). */
	const WAY_ROW  = 'not_refusable';
	const WAY_META = 'refusable';

	/** The rules a value before is composed by: the same as the kind post's. */
	const VALUE_RULES = AB_MCP_Sync_Post_Writer::VALUE_RULES;

	/** Levels of parents followed at most for the check of a loop. */
	const MAX_DEPTH = 64;

	/* ------------------------------------------------------------ prepare */

	/**
	 * What a step writes for one term, from its line of the package of
	 * objects and the item of the plan: the arguments of wp_insert_term() or
	 * wp_update_term(), the columns as they should be stored, the meta by
	 * key, the target form and its hash. A refusal names its lock.
	 *
	 * @param array $line The line of the package (content, form, identity, locks).
	 * @param array $item The item of the plan (op, live_ref).
	 * @param array $ctx  map, addresses, routes; pending (space => staging id
	 *                    => true): the objects the run creates before this
	 *                    one (a parent term), taken as mapped by the check
	 *                    before the first write.
	 * @return array|WP_Error
	 */
	public static function target_of( array $line, array $item, array $ctx ) {
		$taxonomy = isset( $line['identity']['taxonomy'] ) ? (string) $line['identity']['taxonomy'] : '';
		$answer   = AB_MCP_Sync_Kinds::taxonomy( $taxonomy );
		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) || 'term' !== $answer['kind'] || 'z8' !== $answer['status'] ) {
			return self::locked( 'term:taxonomy_rule', sprintf( 'taxonomy %s', $taxonomy ) );
		}
		$held = AB_MCP_Sync_Post_Writer::line_lock( $line );
		if ( null !== $held ) {
			return $held;
		}
		if ( ! isset( $line['content']['row'], $line['content']['meta'], $line['content']['terms'], $line['form'] ) || ! is_array( $line['form'] ) || array() !== $line['content']['terms'] ) {
			return self::locked( 'term:taxonomy_rule', 'the package carries no content of the term (a row of it could not be read on the staging site)' );
		}
		$row = array();
		foreach ( $line['content']['row'] as $column => $value ) {
			$row[ (string) $column ] = AB_MCP_Sync_Post_Writer::bytes( $value );
		}
		foreach ( self::WRITTEN_COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				return self::locked( 'term:taxonomy_rule', sprintf( 'the package names no column %s', $column ) );
			}
		}
		if ( $row['taxonomy'] !== $taxonomy ) {
			return self::locked( 'term:taxonomy_rule', sprintf( 'the row names the taxonomy %1$s, the identity %2$s', $row['taxonomy'], $taxonomy ) );
		}
		// The group of aliases (term_group): an id of this site's own groups,
		// which nothing maps; only 0 is written (and kept, wp_update_term()
		// merges the stored one).
		if ( '0' !== $row['term_group'] ) {
			return self::locked( 'term:term_group', sprintf( 'the term of the staging site carries the term_group %s', $row['term_group'] ) );
		}
		$plan    = AB_MCP_Sync_Refs::plan( $ctx['map'], $ctx['addresses'], isset( $ctx['routes'] ) ? $ctx['routes'] : null );
		$changed = false;
		$pending = isset( $ctx['pending'] ) && is_array( $ctx['pending'] ) ? $ctx['pending'] : array();
		// The parent by its pair; one the run creates before this term is
		// pending (the check only): mapped once its step ran (Eltern zuerst).
		$parent         = (int) $row['parent'];
		$parent_pending = false;
		if ( $parent > 0 ) {
			$mapped = AB_MCP_Sync_Refs::mapped_id( $plan, 'term', $parent );
			if ( null === $mapped && isset( $pending['term'][ $parent ] ) ) {
				$plan['missing'] = array();
				$parent_pending  = true;
				$mapped          = 0;
			}
			if ( null === $mapped ) {
				return self::locked( 'term:reference_unmapped', sprintf( 'the parent %d of the staging site', $parent ) );
			}
			$parent = (int) $mapped;
		}
		// The name and the description with the addresses of this site, as
		// the canonical form reads them (AB_MCP_Sync_Canon::text()).
		$texts = array();
		foreach ( array( 'name', 'description' ) as $column ) {
			$plan['missing']  = array();
			$texts[ $column ] = AB_MCP_Sync_Refs::rewrite_text( $row[ $column ], $plan, array(), $changed );
			if ( array() !== self::unresolved( $plan['missing'], $pending ) ) {
				return self::locked( 'term:reference_unmapped', sprintf( 'a reference in the %s without a counterpart on this site', $column ) );
			}
		}
		// The meta: none on a new term (decision 28); on one here, each value
		// as the metadata API stores it back.
		$meta = array();
		foreach ( $line['content']['meta'] as $pair ) {
			$key = AB_MCP_Sync_Post_Writer::bytes( $pair[0] );
			if ( 'create' === $item['op'] ) {
				return self::locked( 'term:create_with_meta', sprintf( 'meta key %s', $key ) );
			}
			$plan['missing'] = array();
			$written         = AB_MCP_Sync_Post_Writer::meta_value( AB_MCP_Sync_Post_Writer::bytes( $pair[1] ), $plan, $changed, 'term:meta_rule' );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			if ( array() !== self::unresolved( $plan['missing'], $pending ) ) {
				return self::locked( 'term:reference_unmapped', sprintf( 'a reference in the meta key %s without a counterpart on this site', $key ) );
			}
			$meta[ $key ][] = $written;
		}
		$args    = array(
			'name'        => $texts['name'],
			'slug'        => $row['slug'],
			'description' => $texts['description'],
			'parent'      => $parent,
		);
		$columns = array(
			'name'        => $texts['name'],
			'slug'        => $row['slug'],
			'term_group'  => '0',
			'taxonomy'    => $taxonomy,
			'description' => $texts['description'],
			'parent'      => (string) $parent,
		);
		$target  = AB_MCP_Sync_Canon::mapped( $line['form'], $ctx['map'] );
		return array(
			'type'        => $taxonomy,
			'status'      => null,
			'args'        => $args,
			'columns'     => $columns,
			'meta'        => $meta,
			'terms'       => array(),
			'target_form' => $target,
			'target_hash' => AB_MCP_Sync_Canon::hash( $target ),
			'identity'    => $line['identity'],
			'as_draft'    => false,
			'pending'     => $parent_pending,
			// The parents the run gives the terms of this site it writes
			// (the check before the first write only; AB_MCP_Sync_Engine::
			// planned_parents()): the loop of parents is asked of the state
			// the run leaves (branch 9, Codex round 1, B4).
			'parents'     => isset( $ctx['parents'] ) && is_array( $ctx['parents'] ) ? $ctx['parents'] : array(),
		);
	}

	/**
	 * The checks before the first part (decision 17, step 4): the rights of
	 * the account, the group, the parent, the slug, the sanitizers ahead,
	 * the rights per meta key.
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The term here, null for a new one.
	 * @param int      $now      Time (not used: a term has no scheduled state).
	 * @return true|WP_Error
	 */
	public static function precheck( array $prepared, $live_id, $now ) {
		unset( $now );
		$taxonomy = (string) $prepared['type'];
		$object   = get_taxonomy( $taxonomy );
		if ( ! is_object( $object ) || ! isset( $object->cap->manage_terms, $object->cap->edit_terms, $object->cap->assign_terms ) ) {
			return self::locked( 'term:taxonomy_rule', sprintf( 'the taxonomy %s is not registered on this site', $taxonomy ) );
		}
		$current = null;
		if ( null !== $live_id ) {
			$current = self::current( (int) $live_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( ! is_array( $current ) || $current['row']['taxonomy'] !== $taxonomy ) {
				return new WP_Error( 'ab_mcp_sync_target_gone', sprintf( 'The term %1$d of %2$s of this site is no longer there, so nothing is written for it. Make a new report (wp_site_diff) and plan again.', (int) $live_id, $taxonomy ) );
			}
			if ( ! current_user_can( 'edit_term', (int) $live_id ) ) {
				return self::rights( sprintf( 'edit the term %1$d of %2$s (edit_term)', (int) $live_id, $taxonomy ) );
			}
			if ( '0' !== (string) $current['row']['term_group'] ) {
				return self::locked( 'term:term_group', sprintf( 'the term %1$d of this site carries the term_group %2$s', (int) $live_id, (string) $current['row']['term_group'] ) );
			}
		} else {
			// A new term: the rights of the taxonomy (decision 28).
			foreach ( array( 'manage_terms', 'edit_terms', 'assign_terms' ) as $cap ) {
				if ( ! current_user_can( (string) $object->cap->$cap ) ) {
					return self::rights( sprintf( 'make a term of %1$s (%2$s)', $taxonomy, (string) $object->cap->$cap ) );
				}
			}
		}
		$args   = $prepared['args'];
		$parent = (int) $args['parent'];
		// The parent: a term of the taxonomy here, in a taxonomy that has
		// parents; on an update no loop (wp_update_term() runs the filter
		// wp_check_term_hierarchy_for_loops(), which breaks a loop by giving
		// other terms the parent 0, or gives this one 0). A pending parent is
		// made by the run before this term: the boundary checks it mapped.
		// The check before the first write asks the loop of the state the run
		// leaves (the parents it gives the other terms it writes, $prepared
		// ['parents']; two categories that swap their places close no loop);
		// the boundary asks the state as it is then, which wp_update_term()
		// sees (the plan puts a parent the run moves first; Codex round 1, B4).
		if ( $parent > 0 && empty( $prepared['pending'] ) ) {
			if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
				return self::locked( 'term:parent', sprintf( 'the taxonomy %s has no parents on this site', $taxonomy ) );
			}
			$found = AB_MCP_Sync_Rows::term( $parent, $taxonomy );
			if ( ! is_array( $found ) ) {
				return self::locked( 'term:reference_unmapped', sprintf( 'the parent %1$d is not a term of %2$s on this site', $parent, $taxonomy ) );
			}
			$planned = isset( $prepared['parents'] ) && is_array( $prepared['parents'] ) ? $prepared['parents'] : array();
			$loop    = null === $live_id ? null : self::loop( (int) $live_id, $parent, $taxonomy, $planned );
			if ( null !== $loop ) {
				return self::locked( 'term:parent', sprintf( 'the parent %1$d would close a loop of parents on this site: %2$s', $parent, $loop ) );
			}
		}
		// The slug: WordPress refuses an update to a slug another term of the
		// taxonomy holds (or renames it where the parent changes), and gives a
		// new term another one (wp_unique_term_slug()).
		$slug = (string) $args['slug'];
		if ( '' === $slug ) {
			return self::locked( 'term:sanitizer', 'the slug is empty (WordPress would make one from the name)' );
		}
		$holder = self::slug_holder( $slug, $taxonomy );
		if ( null === $holder ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', 'The terms of this site could not be read, so nothing is written. Try again; if it keeps failing, the database error log names the cause.' );
		}
		if ( 0 !== $holder && $holder !== (int) $live_id ) {
			return self::locked( 'term:slug_collision', sprintf( 'the term %1$d of %2$s on this site holds the slug %3$s', $holder, $taxonomy, $slug ) );
		}
		if ( null === $live_id && empty( $prepared['pending'] ) ) {
			$unique = wp_unique_term_slug(
				$slug,
				(object) array(
					'taxonomy' => $taxonomy,
					'parent'   => $parent,
					'slug'     => $slug,
				)
			);
			if ( (string) $unique !== $slug ) {
				return self::locked( 'term:slug_collision', sprintf( 'WordPress would store the slug %1$s for %2$s', (string) $unique, $slug ) );
			}
		}
		// The sanitizers ahead (decision 17): the fields through
		// sanitize_term() as wp_insert_term() and wp_update_term() run it, in
		// the rights of this account (the filters of pre_term_name and
		// pre_term_description: kses without unfiltered_html), compared
		// slashed as they are handed over.
		$refusal = self::sanitizer_refusal( $args, $taxonomy, null === $live_id ? 0 : (int) $live_id );
		if ( null !== $refusal ) {
			return self::locked( 'term:sanitizer', $refusal );
		}
		// The meta of a term here (decision 28): each key whose values differ
		// between the target and this site, with the right per operation
		// through map_meta_cap at the term; a refused key locks the term
		// (the field rule follows with Z8d). Each written value through
		// sanitize_meta() as update_metadata() runs it; the spellings the
		// database compares alike lock (as for posts, post:meta_spelling).
		if ( is_array( $current ) ) {
			foreach ( self::differing( $prepared, $current ) as $key ) {
				$may = self::meta_right( (int) $live_id, $key, self::targets( $prepared, $key ), AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) );
				if ( true !== $may ) {
					return self::locked( '*:meta_without_rule', sprintf( 'meta key %1$s of the term on this site: %2$s', $key, $may ) );
				}
				foreach ( isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array() as $value ) {
					// By the bytes WordPress would store (Codex round 1, B3).
					if ( AB_MCP_Sync_Post_Writer::db_form( sanitize_meta( $key, $value, 'term', $taxonomy ) ) !== AB_MCP_Sync_Post_Writer::stored_form( $value ) ) {
						return self::locked( 'term:sanitizer', sprintf( 'meta key %s (sanitize_meta() changes its value)', $key ) );
					}
				}
				$rows = AB_MCP_Undo_Meta::rows( 'term', (int) $live_id, (string) $key );
				if ( null === $rows ) {
					return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The meta rows of the key %s of the term could not be read, so nothing is written for it. Try again; if it keeps failing, the database error log names the cause.', $key ) );
				}
				foreach ( $rows as $one ) {
					if ( $one['key'] !== (string) $key ) {
						return self::locked( 'term:meta_spelling', sprintf( 'the keys %1$s and %2$s of the term on this site are one key to the database', (string) $key, (string) $one['key'] ) );
					}
				}
			}
		}
		return true;
	}

	/**
	 * Whether the term here is one this version writes as it is: its
	 * canonical form carries no lock (a value the canonical form cannot
	 * read, a credential in a field). The report holds such a term back;
	 * this is the check under the lock of the boundary.
	 *
	 * @param int   $live_id Term.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return true|WP_Error
	 */
	public static function live_rule( $live_id, array $source ) {
		$read = self::live_read( (int) $live_id, $source );
		if ( is_array( $read ) && ! empty( $read['locks'] ) ) {
			$lock = $read['locks'][0];
			return AB_MCP_Sync_Post_Writer::locked( (string) $lock['key'], sprintf( 'the term of this site, at %s', isset( $lock['at'] ) ? (string) $lock['at'] : 'its form' ) );
		}
		return true;
	}

	/**
	 * The parts of the journal of a step: the row, and each meta key whose
	 * values differ between the target and this site (a key equal on both
	 * sides is no part: it stays untouched, decision 28).
	 *
	 * @param array      $prepared target_of().
	 * @param array|null $current  current() of the term here; null for a new one.
	 * @return array[] part, way, before, target.
	 */
	public static function journal_parts( array $prepared, $current ) {
		$out = array(
			array(
				'part'   => 'row',
				'way'    => self::WAY_ROW,
				'before' => is_array( $current ) ? self::row_hash( $current['row'] ) : null,
				'target' => self::row_hash( $prepared['columns'] ),
			),
		);
		foreach ( is_array( $current ) ? self::differing( $prepared, $current ) : array() as $key ) {
			$out[] = array(
				'part'   => 'meta:' . $key,
				'way'    => self::WAY_META,
				'before' => AB_MCP_Sync_Post_Writer::meta_hash( AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) ),
				'target' => AB_MCP_Sync_Post_Writer::meta_hash( self::targets( $prepared, $key ) ),
			);
		}
		return $out;
	}

	/**
	 * The keys whose values differ between the target and the term here,
	 * sorted; never a key whose name is the name of a credential (B9: never
	 * packed, never compared, never written).
	 *
	 * @param array $prepared target_of().
	 * @param array $current  current().
	 * @return string[]
	 */
	public static function differing( array $prepared, array $current ) {
		$keys = array_keys( $prepared['meta'] );
		foreach ( $current['meta'] as $row ) {
			$keys[] = (string) $row['key'];
		}
		$out   = array();
		$extra = AB_MCP_Redactor::extra_keys();
		foreach ( array_unique( array_map( 'strval', $keys ) ) as $key ) {
			if ( AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {
				continue;
			}
			if ( self::targets( $prepared, $key ) !== AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) ) {
				$out[] = $key;
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The values of a key of the target as the metadata API stores them.
	 *
	 * @param array  $prepared target_of().
	 * @param string $key      Key.
	 * @return string[]
	 */
	private static function targets( array $prepared, $key ) {
		$out = array();
		foreach ( isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array() as $value ) {
			$out[] = AB_MCP_Sync_Post_Writer::stored_form( $value );
		}
		return $out;
	}

	/**
	 * The right to bring the rows of a key from these values to those, at
	 * the term, through map_meta_cap (decision 28): the right of every
	 * operation write_meta() makes for it (AB_MCP_Sync_Post_Writer::
	 * meta_ops(); branch 9, Codex round 1, A2), no other.
	 *
	 * @param int      $live_id Term.
	 * @param string   $key     Key.
	 * @param string[] $to      The values to store.
	 * @param string[] $from    The values stored now.
	 * @return true|string What the account may not do.
	 */
	private static function meta_right( $live_id, $key, array $to, array $from ) {
		return AB_MCP_Sync_Post_Writer::ops_right( 'term', $live_id, $key, AB_MCP_Sync_Post_Writer::meta_ops( $to, $from ) );
	}

	/**
	 * Why sanitize_term() would change a field, or null.
	 *
	 * @param array  $args     name, slug, description, parent.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  The term, 0 for a new one.
	 * @return string|null
	 */
	private static function sanitizer_refusal( array $args, $taxonomy, $term_id ) {
		$slashed   = wp_slash(
			array(
				'name'        => (string) $args['name'],
				'slug'        => (string) $args['slug'],
				'description' => (string) $args['description'],
			)
		);
		$sanitized = (array) sanitize_term(
			$slashed + array(
				'parent'   => (int) $args['parent'],
				'taxonomy' => (string) $taxonomy,
				'term_id'  => (int) $term_id,
			),
			(string) $taxonomy,
			'db'
		);
		foreach ( array( 'name', 'slug', 'description' ) as $field ) {
			if ( ! isset( $sanitized[ $field ] ) || (string) $sanitized[ $field ] !== (string) $slashed[ $field ] ) {
				return sprintf( 'field %s', $field );
			}
		}
		if ( '' === trim( (string) $args['name'] ) ) {
			return 'the name is empty';
		}
		return null;
	}

	/**
	 * The loop the parent would close on an update, as WordPress finds it
	 * (wp_find_hierarchy_loop() over the parents of this site, the filter
	 * wp_check_term_hierarchy_for_loops() of wp_update_term()): the ids, or
	 * null for none. With $planned, the parents the run gives the terms it
	 * writes stand for theirs here (the state the run leaves; Codex round 1,
	 * B4); every other term as it is here.
	 *
	 * @param int             $term_id  Term.
	 * @param int             $parent   The new parent.
	 * @param string          $taxonomy Taxonomy.
	 * @param array<int,int>  $planned  Term here => the parent the run gives it.
	 * @return string|null
	 */
	private static function loop( $term_id, $parent, $taxonomy, array $planned = array() ) {
		if ( (int) $parent === (int) $term_id ) {
			return (string) (int) $term_id;
		}
		$parent_of = array() === $planned ? 'wp_get_term_taxonomy_parent_id' : static function ( $id, $tax ) use ( $planned ) {
			return isset( $planned[ (int) $id ] ) ? (int) $planned[ (int) $id ] : wp_get_term_taxonomy_parent_id( (int) $id, $tax );
		};
		$loop = wp_find_hierarchy_loop( $parent_of, (int) $term_id, (int) $parent, array( (string) $taxonomy ) );
		if ( ! is_array( $loop ) || array() === $loop ) {
			return null;
		}
		return implode( ', ', array_map( 'strval', array_slice( array_keys( $loop ), 0, 8 ) ) );
	}

	/**
	 * The term of a taxonomy that holds a slug here, from the rows: its id,
	 * 0 where none does, null where the rows could not be read.
	 *
	 * @param string $slug     Slug.
	 * @param string $taxonomy Taxonomy.
	 * @return int|null
	 */
	private static function slug_holder( $slug, $taxonomy ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the rows, as the comparison reads them.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s ORDER BY t.term_id ASC LIMIT 1", (string) $slug, (string) $taxonomy ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return null === $found ? 0 : (int) $found;
	}

	/* ---------------------------------------------------------- reading */

	/**
	 * The current state of a term here, from its rows: its columns (the
	 * written ones), its meta rows, no terms; null where it is not there; a
	 * WP_Error where a row could not be read, or the term id has rows in two
	 * taxonomies (a term the core did not split, which no ref names alone).
	 *
	 * @param int $live_id Term.
	 * @return array{row:array,meta:array,terms:array}|null|WP_Error
	 */
	public static function current( $live_id ) {
		$taxonomies = AB_MCP_Sync_Rows::term_taxonomies( (int) $live_id );
		if ( null === $taxonomies ) {
			return self::unread( (int) $live_id );
		}
		if ( array() === $taxonomies ) {
			return null;
		}
		if ( 1 !== count( $taxonomies ) ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The term %d of this site has rows in more than one taxonomy (a term WordPress did not split), so nothing is written for it. Check it in wp-admin: saving it there once splits it; then make a new report (wp_site_diff).', (int) $live_id ) );
		}
		$row  = AB_MCP_Sync_Rows::term( (int) $live_id, (string) $taxonomies[0] );
		$meta = AB_MCP_Sync_Rows::meta( 'term', (int) $live_id );
		if ( false === $row ) {
			return null;
		}
		if ( null === $row || null === $meta ) {
			return self::unread( (int) $live_id );
		}
		$columns = array();
		foreach ( self::WRITTEN_COLUMNS as $column ) {
			$columns[ $column ] = (string) $row[ $column ];
		}
		$rows = array();
		foreach ( $meta as $one ) {
			$rows[] = array(
				'meta_id' => (int) $one['meta_id'],
				'key'     => (string) $one['key'],
				'value'   => (string) $one['value'],
			);
		}
		return array(
			'row'   => $columns,
			'meta'  => $rows,
			'terms' => array(),
		);
	}

	/**
	 * @param int $live_id Term.
	 * @return WP_Error
	 */
	private static function unread( $live_id ) {
		return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'A row of the term %d could not be read, so nothing is written for it. Try again; if it keeps failing, the database error log names the cause.', (int) $live_id ) );
	}

	/**
	 * The hash of one named part of a term as it is now: row or meta:<key>;
	 * for no term, null for the row and the hash of no rows for a key.
	 *
	 * @param array|null $current current(), null for no term.
	 * @param string     $part    The name of the part.
	 * @return string|null
	 */
	public static function part_hash( $current, $part ) {
		$part    = (string) $part;
		$current = is_array( $current ) ? $current : array(
			'row'   => array(),
			'meta'  => array(),
			'terms' => array(),
		);
		if ( 'row' === $part ) {
			return array() === $current['row'] ? null : self::row_hash( $current['row'] );
		}
		if ( 0 === strpos( $part, 'meta:' ) ) {
			return AB_MCP_Sync_Post_Writer::meta_hash( AB_MCP_Sync_Post_Writer::meta_values( $current, substr( $part, 5 ) ) );
		}
		// A term carries no terms: the hash of none.
		return AB_MCP_Sync_Post_Writer::terms_hash( array() );
	}

	/**
	 * @param array $columns The written columns of a term row.
	 * @return string
	 */
	public static function row_hash( array $columns ) {
		$row = array();
		foreach ( self::WRITTEN_COLUMNS as $column ) {
			$row[ $column ] = isset( $columns[ $column ] ) ? (string) $columns[ $column ] : '';
		}
		return AB_MCP_Sync_Canon::hash( $row );
	}

	/**
	 * The canonical form of a term here, read as the inventory reads it.
	 *
	 * @param int   $live_id Term.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return array|null
	 */
	private static function live_read( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( 'term' );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => 'term',
				'ref'    => (string) (int) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) ? $read : null;
	}

	/**
	 * The canonical hash of a term here, in this site's ID space.
	 *
	 * @param int   $live_id Term.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return string|null Null where it is not there or could not be read whole.
	 */
	public static function live_hash( $live_id, array $source ) {
		$read = self::live_read( (int) $live_id, $source );
		return is_array( $read ) && isset( $read['hash'] ) && ! empty( $read['complete'] ) ? (string) $read['hash'] : null;
	}

	/* ----------------------------------------------------------- writing */

	/**
	 * The parts the row call writes besides the row: none (wp_insert_term()
	 * and wp_update_term() write no meta).
	 *
	 * @param array      $prepared target_of().
	 * @param int|null   $live_id  The term here, null for a new one.
	 * @param array|null $current  current() right before the row call.
	 * @return array<string,string>
	 */
	public static function with_row( array $prepared, $live_id, $current = null ) {
		unset( $prepared, $live_id, $current );
		return array();
	}

	/**
	 * Whether a part is written by the row call: none of a term.
	 *
	 * @param array  $prepared target_of().
	 * @param string $name     The name of the part.
	 * @return bool
	 */
	public static function through_row( array $prepared, $name ) {
		unset( $prepared, $name );
		return false;
	}

	/**
	 * Write the row: wp_insert_term() for a new term, wp_update_term() for
	 * one here, the name and the description slashed (both unslash them).
	 * A new term whose id is not above every id this site had before the call
	 * is one WordPress gave back for a duplicate (its check after the
	 * insert): not the step's own, refused (fail closed).
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The term here, null for a new one.
	 * @return int|WP_Error The id of the term written.
	 */
	public static function write_row( array $prepared, $live_id ) {
		global $wpdb;
		$taxonomy = (string) $prepared['type'];
		$args     = $prepared['args'];
		$fields   = array(
			'slug'        => (string) $args['slug'],
			'description' => wp_slash( (string) $args['description'] ),
			'parent'      => (int) $args['parent'],
		);
		if ( null !== $live_id ) {
			$done = wp_update_term( (int) $live_id, $taxonomy, array( 'name' => wp_slash( (string) $args['name'] ) ) + $fields );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			if ( ! is_array( $done ) || (int) $done['term_id'] !== (int) $live_id ) {
				return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'WordPress wrote the term under another id than %d (it split a shared term): nothing more of the step is written. Check the term in wp-admin and make a new report (wp_site_diff).', (int) $live_id ) );
			}
			return (int) $live_id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the highest id before the insert, to tell a term WordPress made from one it gave back.
		$highest = (int) $wpdb->get_var( "SELECT MAX(term_id) FROM {$wpdb->terms}" );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', 'The terms of this site could not be read before the term was made, so it was not made. Try again; if it keeps failing, the database error log names the cause.' );
		}
		$done = wp_insert_term( wp_slash( (string) $args['name'] ), $taxonomy, $fields );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$id = is_array( $done ) && isset( $done['term_id'] ) ? (int) $done['term_id'] : 0;
		if ( $id <= $highest ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'WordPress gave back the term %d, which was there before the step (a duplicate of the slug at the same level): it is not the step\'s own, and nothing more is written. Check the terms of the taxonomy in wp-admin and make a new report (wp_site_diff).', $id ) );
		}
		return $id;
	}

	/**
	 * Write one meta key of a term with a call of its own, to exactly the
	 * values of the target; the right at the term asked right before.
	 *
	 * @param int        $live_id  Term.
	 * @param string     $name     meta:<key>.
	 * @param array      $prepared target_of().
	 * @param array|null $pre      current() right before the call.
	 * @return true|WP_Error
	 */
	public static function write_part( $live_id, $name, array $prepared, $pre ) {
		$name = (string) $name;
		if ( 0 !== strpos( $name, 'meta:' ) ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The part %s is of a name the kind term does not write. Check the term in wp-admin.', $name ) );
		}
		$key  = substr( $name, 5 );
		$from = is_array( $pre ) ? AB_MCP_Sync_Post_Writer::meta_values( $pre, $key ) : array();
		$may  = self::meta_right( (int) $live_id, $key, self::targets( $prepared, $key ), $from );
		if ( true !== $may ) {
			return new WP_Error( 'ab_mcp_sync_rights', sprintf( 'The meta %1$s of the term was not written: %2$s at the term. Connect with an account that may, or leave the term out of the plan.', $key, $may ) );
		}
		return AB_MCP_Sync_Post_Writer::write_meta( (int) $live_id, $key, isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array(), $from, 'term' );
	}

	/**
	 * After the writes of a step (decision 33): the caches of the term.
	 *
	 * @param int $live_id Term.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		$taxonomies = AB_MCP_Sync_Rows::term_taxonomies( (int) $live_id );
		foreach ( is_array( $taxonomies ) ? $taxonomies : array() as $taxonomy ) {
			clean_term_cache( (int) $live_id, (string) $taxonomy );
		}
		wp_cache_delete( (int) $live_id, 'term_meta' );
		return array( 'term', 'term_meta' );
	}

	/**
	 * What the takeover lock of a step covers: the term here, and new terms
	 * of its taxonomy (pre_insert_term: no other hand makes a term with the
	 * slug while the step writes).
	 *
	 * @param int|null $live_id  Term, null for a new one.
	 * @param string   $taxonomy Taxonomy.
	 * @return array{posts:array,taxonomies:string[],terms:array}
	 */
	public static function guard_of( $live_id, $taxonomy ) {
		return array(
			'posts'      => array(),
			'taxonomies' => array( (string) $taxonomy ),
			'terms'      => null === $live_id ? array() : array( (int) $live_id => (string) $taxonomy ),
		);
	}

	/**
	 * @param array $object The object of a journal.
	 * @return string Its taxonomy.
	 */
	public static function subtype_of( array $object ) {
		return isset( $object['identity']['taxonomy'] ) ? (string) $object['identity']['taxonomy'] : '';
	}

	/**
	 * @param array $prepared target_of().
	 * @return string The name of the written term.
	 */
	public static function title_of( array $prepared ) {
		return (string) $prepared['args']['name'];
	}

	/* ---------------------------------------------------------- way back */

	/**
	 * Whether this account may put a part back (decision 28, as forward):
	 * edit_term for every part; for a meta key the operation at the term.
	 *
	 * @param string $part    Part (row, meta:<key>).
	 * @param int    $live_id Term.
	 * @param array  $backup  current() of the backup.
	 * @param array  $now     current() as read right before the part's turn.
	 * @return true|string
	 */
	public static function restore_right( $part, $live_id, array $backup, array $now ) {
		$part = (string) $part;
		$id   = (int) $live_id;
		if ( ! current_user_can( 'edit_term', $id ) ) {
			return sprintf( 'edit the term %1$d (edit_term%2$s)', $id, 'row' === $part ? '' : ', for the part ' . $part );
		}
		if ( 0 === strpos( $part, 'meta:' ) ) {
			$key = substr( $part, 5 );
			// The operations of the way back, row by row (restore_meta()).
			$may = AB_MCP_Sync_Post_Writer::ops_right( 'term', $id, $key, AB_MCP_Sync_Post_Writer::restore_ops( AB_MCP_Sync_Post_Writer::meta_values( $backup, $key ), isset( $now['meta'] ) ? AB_MCP_Sync_Post_Writer::meta_values( $now, $key ) : array() ) );
			return true === $may ? true : sprintf( 'change the meta key %1$s of the term (%2$s)', $key, $may );
		}
		return true;
	}

	/**
	 * Put one part back from the backup of the step (decision 23).
	 *
	 * @param string $name    The name of the part.
	 * @param int    $live_id Term.
	 * @param array  $backup  current() of the backup.
	 * @param string $hash    The hash the part has to hold as it is put back.
	 * @param int    $now     Time.
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_part( $name, $live_id, array $backup, $hash, $now ) {
		unset( $now );
		$name = (string) $name;
		if ( 'row' === $name ) {
			return self::restore_row( (int) $live_id, $backup['row'], (string) $hash );
		}
		if ( 0 === strpos( $name, 'meta:' ) ) {
			$key = substr( $name, 5 );
			return AB_MCP_Sync_Post_Writer::restore_meta( (int) $live_id, $key, AB_MCP_Sync_Post_Writer::meta_values( $backup, $key ), (string) $hash, 'term' );
		}
		return array(
			'state' => 'left',
			'note'  => sprintf( 'A part %s of a term is of a name this version does not know: left as it is.', $name ),
		);
	}

	/**
	 * Put the row back through wp_update_term() (decision 23: the core way,
	 * after the byte for byte check): only where the row now hashes to what
	 * the step read back ($written), and the backed-up slug, parent and
	 * fields pass the same checks as forward (no other term holds the slug,
	 * the parent is there and closes no loop, the sanitizers keep them).
	 *
	 * @param int    $live_id Term.
	 * @param array  $backup  The backed-up columns.
	 * @param string $written The hash the step read back.
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_row( $live_id, array $backup, $written ) {
		$current = self::current( (int) $live_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current ) {
			return array(
				'state' => 'left',
				'note'  => 'The term is no longer on this site.',
			);
		}
		$before = self::row_hash( $backup );
		$hash   = self::row_hash( $current['row'] );
		if ( hash_equals( $before, $hash ) ) {
			return array( 'state' => 'unchanged' );
		}
		if ( ! hash_equals( (string) $written, $hash ) ) {
			return array(
				'state' => 'left',
				'note'  => 'The row of the term was changed since the step wrote it, so it is left as it is.',
			);
		}
		$taxonomy = (string) $backup['taxonomy'];
		$holder   = self::slug_holder( (string) $backup['slug'], $taxonomy );
		if ( null === $holder || ( 0 !== $holder && $holder !== (int) $live_id ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'Not put back: the slug %s of the backup is held by another term of %s on this site now (or the terms could not be read), so WordPress would refuse or rename it. Set it by hand in wp-admin.', (string) $backup['slug'], $taxonomy ),
			);
		}
		$parent = (int) $backup['parent'];
		if ( $parent > 0 && ( ! is_array( AB_MCP_Sync_Rows::term( $parent, $taxonomy ) ) || null !== self::loop( (int) $live_id, $parent, $taxonomy ) ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'Not put back: the parent %d of the backup is no longer a term of %s here, or would close a loop of parents now. Set the parent by hand in wp-admin.', $parent, $taxonomy ),
			);
		}
		$args = array(
			'name'        => (string) $backup['name'],
			'slug'        => (string) $backup['slug'],
			'description' => (string) $backup['description'],
			'parent'      => $parent,
		);
		$refusal = self::sanitizer_refusal( $args, $taxonomy, (int) $live_id );
		if ( null !== $refusal ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'Not put back: the sanitizing of this account would change the backed-up %s (kses without unfiltered_html). Set it by hand in wp-admin with an account that may.', $refusal ),
			);
		}
		// The last read, after the sanitizers and their filters (Codex round 2
		// of branch 11, A6): the row as the step wrote it, or it is left.
		$again = self::current( (int) $live_id );
		if ( ! is_array( $again ) || ! hash_equals( (string) $written, self::row_hash( $again['row'] ) ) ) {
			return array(
				'state' => 'left',
				'note'  => 'The row of the term changed as it was put back (a hook of this site), so it is left as it is.',
			);
		}
		$done = wp_update_term(
			(int) $live_id,
			$taxonomy,
			array(
				'name'        => wp_slash( $args['name'] ),
				'slug'        => $args['slug'],
				'description' => wp_slash( $args['description'] ),
				'parent'      => $parent,
			)
		);
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$after = self::current( (int) $live_id );
		if ( ! is_array( $after ) || ! hash_equals( $before, self::row_hash( $after['row'] ) ) ) {
			return array(
				'state' => 'unclear',
				'note'  => 'The row of the term was written back but does not read as the backup.',
			);
		}
		return array( 'state' => 'restored' );
	}

	/* ------------------------------------------------- the value before */

	/**
	 * What a part held before the step, from the backup, for a part the
	 * answer names as left: the columns of the row that differ from the row
	 * now (or NAMED_COLUMNS where it is gone), the values of a meta key;
	 * never a credential (AB_MCP_Sync_Post_Writer::shown_value()).
	 *
	 * @param string     $part    Part (row, meta:<key>).
	 * @param array|null $backup  current() of the backup; null for a new object.
	 * @param array|null $now     current() as read now; null where unknown or gone.
	 * @param int|null   $live_id Term.
	 * @return string
	 */
	public static function before_value( $part, $backup, $now, $live_id ) {
		unset( $live_id );
		if ( ! is_array( $backup ) ) {
			return 'none: the object was not on this site before the run.';
		}
		$part = (string) $part;
		if ( 0 === strpos( $part, 'meta:' ) ) {
			$text = AB_MCP_Sync_Post_Writer::meta_before( substr( $part, 5 ), AB_MCP_Sync_Post_Writer::meta_values( $backup, substr( $part, 5 ) ) );
			return AB_MCP_Sync_Post_Writer::cut( $text, AB_MCP_Sync_Post_Writer::PART_CHARS );
		}
		$columns = array();
		$row_now = is_array( $now ) ? $now['row'] : null;
		foreach ( self::WRITTEN_COLUMNS as $column ) {
			$was = isset( $backup['row'][ $column ] ) ? (string) $backup['row'][ $column ] : '';
			if ( null === $row_now ? in_array( $column, self::NAMED_COLUMNS, true ) : $was !== ( isset( $row_now[ $column ] ) ? (string) $row_now[ $column ] : '' ) ) {
				$columns[ $column ] = $was;
			}
		}
		if ( array() === $columns ) {
			foreach ( self::NAMED_COLUMNS as $column ) {
				$columns[ $column ] = isset( $backup['row'][ $column ] ) ? (string) $backup['row'][ $column ] : '';
			}
		}
		$out = array();
		foreach ( $columns as $column => $was ) {
			$out[] = $column . ': ' . AB_MCP_Sync_Post_Writer::shown_value( '', $was );
		}
		return AB_MCP_Sync_Post_Writer::cut( implode( '; ', $out ), AB_MCP_Sync_Post_Writer::PART_CHARS );
	}

	/* ----------------------------------------------------------- helpers */

	/**
	 * The references without a counterpart that are not pending.
	 *
	 * @param array[] $missing space, id.
	 * @param array   $pending space => staging id => true.
	 * @return array[]
	 */
	private static function unresolved( array $missing, array $pending ) {
		$out = array();
		foreach ( $missing as $m ) {
			if ( ! isset( $pending[ (string) ( $m['space'] ?? '' ) ][ (int) ( $m['id'] ?? 0 ) ] ) ) {
				$out[] = $m;
			}
		}
		return $out;
	}

	/**
	 * A refusal with the lock of its stage (AB_MCP_Sync_Kinds::LOCKS).
	 *
	 * @param string $key    Lock key (term:…).
	 * @param string $detail What was found.
	 * @return WP_Error
	 */
	public static function locked( $key, $detail ) {
		return AB_MCP_Sync_Post_Writer::locked( $key, $detail );
	}

	/**
	 * @param string $what What the account may not do.
	 * @return WP_Error
	 */
	private static function rights( $what ) {
		return new WP_Error( 'ab_mcp_sync_rights', sprintf( 'Not written: this account may not %s on this site. Connect with an account that may, or leave the object out of the plan.', $what ), array( 'needs' => $what ) );
	}
}
