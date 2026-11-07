<?php
/**
 * The write side of the kind post (contract A of the plan of 01.11.2026;
 * Z8 branch 8, decisions 17, 19, 21 and 23): what a step of a run writes
 * for one post or page, how it is backed up, read back and put back. The
 * first kind the takeover writes end to end; the full engine of the kind
 * (its field schema, the rights per meta key, slug collisions, every
 * sanitizer ahead, the core consequences with the menu automatic) is
 * branch 9: everything of the kind this version does not carry to its end
 * is refused here, fail closed, with the lock of its stage.
 *
 * WHAT THIS VERSION WRITES (TYPES, TAXONOMIES, CORE_META): a post or page
 * (its row: status and dates, title, content, excerpt, slug, parent, menu
 * order, author, comment and ping status, mime type), its meta of the one
 * core key with an API of its own (_wp_page_template) and, on a post that
 * is there, plain keys (not beginning with _) the account may edit on it
 * (edit_post_meta, and delete_post_meta for a key only this site has, which
 * the step removes; never a protected, credential or page builder key), its
 * terms of category, post_tag and post_format. A post of another core type
 * (templates, template parts, global styles, navigations, reusable blocks),
 * a meta key outside that rule, a term of another taxonomy, a new post with
 * plain meta (the right to it is answered by the object only, Z8d), a new
 * post whose slug another post of its type and parent has here, a field
 * WordPress's own sanitizing would change (kses without unfiltered_html), an
 * author without an account here, a reference without a counterpart, a
 * status WordPress would store otherwise (a scheduled date that passed) are
 * refused with their lock (AB_MCP_Sync_Kinds::LOCKS) before anything is
 * written; a page of the top level that would be published or scheduled
 * while a menu of this site adds pages by itself is refused unless the
 * person confirmed the choice «as draft» (decision 21).
 *
 * THE WAY FORWARD (decision 17): the row through wp_insert_post() and
 * wp_update_post() with both date columns and edit_date (the way of the
 * free writer, AB_MCP_Tools_Content), the status the plan confirmed; meta
 * through update_metadata(), add_metadata() and delete_metadata() with
 * wp_slash(); terms through wp_set_object_terms(). The content, title,
 * excerpt and meta values carry the references and addresses of the
 * staging site rewritten into this site's (AB_MCP_Sync_Refs::plan(),
 * rewrite_content(), rewrite_text(), rewrite_value()), by the map of the
 * pairs; a reference the map does not hold refuses the step.
 *
 * THE TARGET (decision 17, Sollwert): the staging form mapped into the ID
 * space of this site (AB_MCP_Sync_Canon::mapped()) with the confirmed
 * choice applied (post_status draft); the step is done only where the
 * canonical form of the written post, read from the rows, hashes to it.
 *
 * THE WAY BACK (decision 23): a part goes back only where its hash now is
 * the hash the step read back (its own state); the row through
 * wp_update_post() with the backed-up columns and edit_date, after the
 * check of the status WordPress would store now (a backed-up future whose
 * date passed is left as «check needed»); meta rows raw, byte for byte
 * conditional (CAST AS BINARY and OCTET_LENGTH in the WHERE); terms through
 * wp_set_object_terms() to the backed-up set. A post the step made is never
 * removed.
 *
 * Reads for comparison come from the rows (AB_MCP_Sync_Rows); the writes and
 * the rights go through WordPress, named one by one in SyncSafeCallsTest.
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
require_once __DIR__ . '/class-sync-exclusions.php';
require_once __DIR__ . '/class-sync-db-kinds.php';
require_once __DIR__ . '/class-sync-credentials.php';
require_once dirname( __DIR__ ) . '/tools/class-tools-content.php';
require_once dirname( __DIR__ ) . '/builders/class-builders.php';
require_once dirname( __DIR__ ) . '/class-redactor.php';

/**
 * Class AB_MCP_Sync_Post_Writer
 */
final class AB_MCP_Sync_Post_Writer {

	/** The post types this version writes. */
	const TYPES = array( 'post', 'page' );

	/** The taxonomies this version writes the terms of. */
	const TAXONOMIES = array( 'category', 'post_tag', 'post_format' );

	/** The core meta keys with an API of their own this version writes. */
	const CORE_META = array( '_wp_page_template' );

	/**
	 * The taxonomies whose terms are part of the identity of the post that
	 * carries them (AB_MCP_Sync_Kinds::OWNERSHIP, kind post): compared and
	 * written by their slug, not by a term id (the term may not exist here
	 * yet; wp_set_object_terms() makes it; Codex round 1 of branch 8, B6).
	 */
	const IDENTITY_TAXONOMIES = array( 'post_format' );

	/** The statuses a target may have (the trash is a deletion, decision 22; auto-draft no content). */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/** The columns of a post row the writer hands to wp_insert_post(). */
	const WRITTEN_COLUMNS = array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'post_content_filtered', 'post_parent', 'menu_order', 'post_type', 'post_mime_type' );

	/** The parts of a step, by their way: refusable (the lock of decision 19 holds) or not. */
	const WAY_ROW   = 'refusable';
	const WAY_META  = 'refusable';
	const WAY_TERMS = 'not_refusable';

	/** The rules a value before is composed by (value_rules); a value of other rules is not shown. */
	const VALUE_RULES = 2;

	/** Characters one value is shown with at most in value_before (before_value()). */
	const VALUE_CHARS = 80;

	/** Characters the value before of one part is shown with at most. */
	const PART_CHARS = 320;

	/** Columns of the row shown by their length (and the revision that holds them), never whole. */
	const LONG_COLUMNS = array( 'post_content', 'post_content_filtered', 'post_excerpt' );

	/** The columns of the row named where the row is no longer there to compare. */
	const NAMED_COLUMNS = array( 'post_title', 'post_status', 'post_name', 'post_content' );

	/* ------------------------------------------------------------ prepare */

	/**
	 * What a step writes for one object, from its line of the package of
	 * objects and the item of the plan: the post row for wp_insert_post(),
	 * the columns as they should be stored, the meta by key, the terms by
	 * taxonomy, the target form and its hash. A refusal names its lock.
	 *
	 * @param array $line The line of the package (content, form, identity, refs).
	 * @param array $item The item of the plan (op, live_ref, as_draft).
	 * @param array $ctx  map (space => staging id => live id), addresses
	 *                    (staging home => live home), routes (this site's),
	 *                    now (time), users (login => id, cached); pending
	 *                    (space => staging id => true): objects the same run
	 *                    creates before this one, which the check before the
	 *                    first write takes as mapped (their live id exists
	 *                    only once their step ran; the boundary maps them by
	 *                    the pairs then; B1).
	 * @return array|WP_Error
	 */
	public static function target_of( array $line, array $item, array $ctx ) {
		$type = isset( $line['identity']['post_type'] ) ? (string) $line['identity']['post_type'] : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return self::locked( 'post:engine_type', sprintf( 'post type %s', $type ) );
		}
		if ( ! isset( $line['content']['row'], $line['content']['meta'], $line['content']['terms'], $line['form'] ) || ! is_array( $line['form'] ) ) {
			return self::locked( 'post:engine_type', 'the package carries no content of the object (a row of it could not be read on the staging site)' );
		}
		$row = array();
		foreach ( $line['content']['row'] as $column => $value ) {
			$row[ (string) $column ] = self::bytes( $value );
		}
		foreach ( self::WRITTEN_COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				return self::locked( 'post:engine_type', sprintf( 'the package names no column %s', $column ) );
			}
		}
		if ( '' !== $row['post_password'] ) {
			return self::locked( 'post:password_protected', 'post_password' );
		}
		$status = 'create' === $item['op'] || 'update' === $item['op'] ? $row['post_status'] : '';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return self::locked( 'post:engine_type', sprintf( 'post status %s', $status ) );
		}
		if ( ! empty( $item['as_draft'] ) ) {
			$status = 'draft';
		}
		$plan    = AB_MCP_Sync_Refs::plan( $ctx['map'], $ctx['addresses'], isset( $ctx['routes'] ) ? $ctx['routes'] : null );
		$changed = false;
		$pending = isset( $ctx['pending'] ) && is_array( $ctx['pending'] ) ? $ctx['pending'] : array();

		// The parent by its pair; one the run creates before this object is
		// pending: mapped once its step ran (the check only).
		$parent         = (int) $row['post_parent'];
		$parent_pending = false;
		if ( $parent > 0 ) {
			$mapped = AB_MCP_Sync_Refs::mapped_id( $plan, 'post', $parent );
			if ( null === $mapped && isset( $pending['post'][ $parent ] ) ) {
				$plan['missing']  = array();
				$parent_pending   = true;
				$mapped           = 0;
			}
			if ( null === $mapped ) {
				return self::locked( 'post:reference_unmapped', sprintf( 'post_parent %d of the staging site', $parent ) );
			}
			$parent = $mapped;
		}
		// The author by login.
		$author = 0;
		if ( (int) $row['post_author'] > 0 ) {
			$login = self::login_of( $line, 'post_author' );
			$user  = null === $login ? null : self::user_by_login( $login, $ctx );
			if ( null === $user ) {
				return self::locked( 'post:author_unknown', null === $login ? 'post_author' : sprintf( 'login %s', $login ) );
			}
			$author = (int) $user;
		}
		// The content and the texts, with the references and addresses of
		// this site.
		$content = AB_MCP_Sync_Refs::rewrite_content( $row['post_content'], $plan );
		$missing = self::unresolved( $content['missing'], $pending );
		if ( array() !== $missing ) {
			return self::locked( 'post:reference_unmapped', self::missing_text( $missing ) );
		}
		$texts = array();
		foreach ( array( 'post_title', 'post_excerpt', 'post_content_filtered' ) as $column ) {
			$texts[ $column ] = AB_MCP_Sync_Refs::rewrite_text( $row[ $column ], $plan, array(), $changed );
			$missing          = self::unresolved( $plan['missing'], $pending );
			if ( array() !== $missing ) {
				return self::locked( 'post:reference_unmapped', self::missing_text( $missing ) );
			}
		}
		// The meta by the rule of this version.
		$meta = array();
		foreach ( $line['content']['meta'] as $pair ) {
			$key   = self::bytes( $pair[0] );
			$value = self::bytes( $pair[1] );
			if ( ! self::meta_allowed( $key ) ) {
				return self::locked( 'post:meta_rule', sprintf( 'meta key %s', $key ) );
			}
			if ( 'create' === $item['op'] && ! in_array( $key, self::CORE_META, true ) ) {
				return self::locked( 'post:create_with_object_meta', sprintf( 'meta key %s', $key ) );
			}
			$written = self::meta_value( $value, $plan, $changed );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$missing = self::unresolved( $plan['missing'], $pending );
			if ( array() !== $missing ) {
				return self::locked( 'post:reference_unmapped', self::missing_text( $missing ) );
			}
			$meta[ $key ][] = $written;
		}
		// The terms: by pair for the taxonomies of the term kind, by slug for
		// those that are part of the identity (post_format).
		$terms = array();
		foreach ( $line['content']['terms'] as $term ) {
			$taxonomy = self::bytes( $term['taxonomy'] );
			if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
				return self::locked( 'post:taxonomy_rule', sprintf( 'taxonomy %s', $taxonomy ) );
			}
			if ( 'post_format' === $taxonomy ) {
				$terms[ $taxonomy ][] = self::bytes( $term['slug'] );
				continue;
			}
			$mapped = AB_MCP_Sync_Refs::mapped_id( $plan, 'term', (int) $term['term_id'] );
			if ( null === $mapped ) {
				return self::locked( 'post:reference_unmapped', sprintf( 'term %d of the taxonomy %s of the staging site', (int) $term['term_id'], $taxonomy ) );
			}
			$terms[ $taxonomy ][] = (int) $mapped;
		}
		foreach ( $terms as $taxonomy => $list ) {
			sort( $terms[ $taxonomy ] );
		}
		$postarr = array(
			'post_author'           => $author,
			'post_date'             => $row['post_date'],
			'post_date_gmt'         => $row['post_date_gmt'],
			'post_content'          => $content['content'],
			'post_title'            => $texts['post_title'],
			'post_excerpt'          => $texts['post_excerpt'],
			'post_status'           => $status,
			'comment_status'        => $row['comment_status'],
			'ping_status'           => $row['ping_status'],
			'post_password'         => '',
			'post_name'             => $row['post_name'],
			'post_content_filtered' => $texts['post_content_filtered'],
			'post_parent'           => $parent,
			'menu_order'            => (int) $row['menu_order'],
			'post_type'             => $type,
			'post_mime_type'        => $row['post_mime_type'],
			'edit_date'             => true,
		);
		// The template through the core way (decisions 17 and 28): page_template
		// in the data of wp_insert_post(), which checks it against the theme
		// and stores _wp_page_template itself (B7).
		if ( isset( $meta['_wp_page_template'][0] ) && is_string( $meta['_wp_page_template'][0] ) && '' !== $meta['_wp_page_template'][0] ) {
			$postarr['page_template'] = $meta['_wp_page_template'][0];
		}
		// The columns as WordPress stores them: the UTC date derived where
		// it is missing on a post that is not floating (wp_insert_post()).
		$columns = array_intersect_key( $postarr, array_flip( self::WRITTEN_COLUMNS ) );
		if ( ( '' === $columns['post_date_gmt'] || '0000-00-00 00:00:00' === $columns['post_date_gmt'] ) && ! in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			$columns['post_date_gmt'] = get_gmt_from_date( $columns['post_date'] );
		}
		$columns['post_author'] = (string) $columns['post_author'];
		$columns['post_parent'] = (string) $columns['post_parent'];
		$columns['menu_order']  = (string) $columns['menu_order'];
		// The target: the staging form in this site's ID space, with the choice.
		$target = AB_MCP_Sync_Canon::mapped( $line['form'], $ctx['map'] );
		if ( ! empty( $item['as_draft'] ) && isset( $target['row'] ) && is_array( $target['row'] ) ) {
			$target['row']['post_status'] = 'draft';
		}
		return array(
			'type'        => $type,
			'status'      => $status,
			'postarr'     => $postarr,
			'columns'     => $columns,
			'meta'        => $meta,
			'terms'       => $terms,
			'target_form' => $target,
			'target_hash' => AB_MCP_Sync_Canon::hash( $target ),
			'identity'    => $line['identity'],
			'as_draft'    => ! empty( $item['as_draft'] ),
			'pending'     => $parent_pending,
		);
	}

	/**
	 * The checks before the first part (decision 17, step 4): the rights of
	 * the account, the status WordPress would store, the menu automatic,
	 * the slug of a new post, the sanitizers ahead.
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The post here, null for a new one.
	 * @param int      $now      Time.
	 * @return true|WP_Error
	 */
	public static function precheck( array $prepared, $live_id, $now ) {
		$type = $prepared['type'];
		$pto  = get_post_type_object( $type );
		if ( ! is_object( $pto ) || ! isset( $pto->cap ) ) {
			return self::locked( 'post:engine_type', sprintf( 'the post type %s is not registered on this site', $type ) );
		}
		$live = null;
		if ( null !== $live_id ) {
			$live = AB_MCP_Sync_Rows::post( (int) $live_id );
			if ( ! is_array( $live ) ) {
				return new WP_Error( 'ab_mcp_sync_target_gone', sprintf( 'The post %d of this site is no longer there, so nothing is written for it. Make a new report (wp_site_diff) and plan again.', (int) $live_id ) );
			}
			if ( ! current_user_can( 'edit_post', (int) $live_id ) ) {
				return self::rights( sprintf( 'edit the %1$s %2$d (edit_post)', $type, (int) $live_id ) );
			}
		} elseif ( ! current_user_can( $pto->cap->create_posts ) ) {
			return self::rights( sprintf( 'create a %1$s (%2$s)', $type, (string) $pto->cap->create_posts ) );
		}
		$status = $prepared['status'];
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ( null === $live || $live['post_status'] !== $status ) && ! current_user_can( $pto->cap->publish_posts ) ) {
			return self::rights( sprintf( 'publish a %1$s (%2$s)', $type, (string) $pto->cap->publish_posts ) );
		}
		if ( (int) $prepared['postarr']['post_author'] !== (int) get_current_user_id() && ! current_user_can( $pto->cap->edit_others_posts ) ) {
			return self::rights( sprintf( 'write a %1$s of another author (%2$s)', $type, (string) $pto->cap->edit_others_posts ) );
		}
		foreach ( array_keys( $prepared['meta'] ) as $key ) {
			if ( null !== $live_id && ! in_array( $key, self::CORE_META, true ) && ! current_user_can( 'edit_post_meta', (int) $live_id, $key ) ) {
				return self::rights( sprintf( 'edit the meta key %s of the post (edit_post_meta)', $key ) );
			}
		}
		// A plain key only this site has is removed by the step (write_meta()
		// with no value): the right to remove it, per key, through
		// map_meta_cap, before anything is written (decision 28; the metadata
		// API asks no right itself; GitHub Codex round 1 of branch 8). The
		// core key with an API of its own goes with edit_post.
		if ( null !== $live_id ) {
			$current = self::current( (int) $live_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			foreach ( is_array( $current ) ? $current['meta'] : array() as $row ) {
				if ( ! isset( $prepared['meta'][ $row['key'] ] ) && ! in_array( $row['key'], self::CORE_META, true ) && ! current_user_can( 'delete_post_meta', (int) $live_id, $row['key'] ) ) {
					return self::rights( sprintf( 'remove the meta key %s of the post (delete_post_meta)', $row['key'] ) );
				}
			}
		}
		// The template the theme of this site offers for the type (B7): as
		// wp_insert_post() checks page_template (WP_Theme::get_page_templates());
		// one it does not offer would be stored as default.
		if ( isset( $prepared['postarr']['page_template'] ) && 'default' !== $prepared['postarr']['page_template'] ) {
			$templates = wp_get_theme()->get_page_templates( null, $type );
			if ( ! is_array( $templates ) || ! isset( $templates[ $prepared['postarr']['page_template'] ] ) ) {
				return self::locked( 'post:page_template', sprintf( 'the template %1$s for a %2$s', $prepared['postarr']['page_template'], $type ) );
			}
		}
		foreach ( $prepared['terms'] as $taxonomy => $list ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return self::locked( 'post:taxonomy_rule', sprintf( 'the taxonomy %s is not registered on this site', $taxonomy ) );
			}
			foreach ( $list as $term ) {
				if ( is_int( $term ) && ! current_user_can( 'assign_term', $term ) ) {
					return self::rights( sprintf( 'assign the term %1$d of %2$s (assign_term)', $term, $taxonomy ) );
				}
			}
		}
		// The status WordPress would store, from the very data handed over
		// (decision 17; AB_MCP_Tools_Content::saved_status()).
		$gmt   = $prepared['columns']['post_date_gmt'];
		$saved = AB_MCP_Tools_Content::saved_status( $type, $status, $gmt, (int) $now );
		if ( $saved !== $status ) {
			return self::locked( 'post:status_date', sprintf( 'WordPress would store %1$s for the status %2$s dated %3$s UTC', $saved, $status, $gmt ) );
		}
		$refusal = AB_MCP_Tools_Content::status_date_refusal( $type, null === $live ? null : $live['post_status'], $status, $gmt, true, (int) $now );
		if ( '' !== $refusal ) {
			return self::locked( 'post:status_date', sprintf( '%1$s dated %2$s UTC: %3$s', $status, $gmt, $refusal ) );
		}
		// A page of the top level that would be published or scheduled while
		// a menu adds pages by itself (decision 21): the choice «as draft».
		// A page whose parent the run creates before it is not of the top
		// level (pending; the boundary checks it with the parent mapped).
		$top = 0 === (int) $prepared['postarr']['post_parent'] && empty( $prepared['pending'] );
		if ( 'page' === $type && $top && in_array( $status, array( 'publish', 'future' ), true ) && self::menu_adds_pages() ) {
			return self::locked( 'post:menu_automatic', sprintf( 'the page would be %s at the top level', $status ) );
		}
		// A new post whose slug is taken: WordPress would rename it, and the
		// written post would not be the target (branch 9 settles the rule).
		// With a pending parent nothing is under it yet.
		if ( null === $live_id && empty( $prepared['pending'] ) && '' !== $prepared['postarr']['post_name'] && self::slug_taken( $prepared['postarr']['post_name'], $type, (int) $prepared['postarr']['post_parent'] ) ) {
			return self::locked( 'post:slug_collision', sprintf( 'slug %s', $prepared['postarr']['post_name'] ) );
		}
		// The sanitizers ahead (decision 17): the fields through
		// sanitize_post() as wp_insert_post() runs it, in the rights of this
		// account (kses without unfiltered_html), compared slashed.
		$slashed   = wp_slash( array_intersect_key( $prepared['postarr'], array_flip( array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered', 'post_name' ) ) ) );
		$sanitized = (array) sanitize_post( $slashed + array( 'post_type' => $type ), 'db' );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered' ) as $field ) {
			if ( ! isset( $sanitized[ $field ] ) || (string) $sanitized[ $field ] !== (string) $slashed[ $field ] ) {
				return self::locked( 'post:sanitizer', sprintf( 'field %s', $field ) );
			}
		}
		return true;
	}

	/* ---------------------------------------------------------- reading */

	/**
	 * The current state of a post here, from its rows: its columns (the
	 * written ones), its meta rows and its terms; null where it is not
	 * there; a WP_Error where a row could not be read.
	 *
	 * @param int $live_id Post.
	 * @return array{row:array,meta:array,terms:array}|null|WP_Error
	 */
	public static function current( $live_id ) {
		$row = AB_MCP_Sync_Rows::post( (int) $live_id );
		if ( false === $row ) {
			return null;
		}
		$meta  = AB_MCP_Sync_Rows::meta( 'post', (int) $live_id );
		$terms = AB_MCP_Sync_Rows::object_terms( (int) $live_id );
		if ( null === $row || null === $meta || null === $terms ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'A row of the post %d could not be read, so nothing is written for it. Try again; if it keeps failing, the database error log names the cause.', (int) $live_id ) );
		}
		$columns = array();
		foreach ( self::WRITTEN_COLUMNS as $column ) {
			$columns[ $column ] = (string) $row[ $column ];
		}
		$rows = array();
		foreach ( $meta as $one ) {
			if ( AB_MCP_Sync_Exclusions::post_meta( $one['key'] ) ) {
				continue;
			}
			$rows[] = array(
				'meta_id' => (int) $one['meta_id'],
				'key'     => (string) $one['key'],
				'value'   => (string) $one['value'],
			);
		}
		$set = array();
		foreach ( $terms as $one ) {
			$set[] = array(
				'taxonomy'   => (string) $one['taxonomy'],
				'term_id'    => (int) $one['term_id'],
				'slug'       => (string) $one['slug'],
				'term_order' => (int) $one['term_order'],
			);
		}
		return array(
			'row'   => $columns,
			'meta'  => $rows,
			'terms' => $set,
		);
	}

	/**
	 * The hash of each part of a post as it is now: row, meta:<key> for
	 * every key asked and every key present, terms:<taxonomy> likewise.
	 *
	 * @param array|null $current    current(), null for no post.
	 * @param string[]   $meta_keys  Keys the step writes.
	 * @param string[]   $taxonomies Taxonomies the step writes.
	 * @return array<string,string|null> part => hash (null where the post is not there).
	 */
	public static function hashes( $current, array $meta_keys, array $taxonomies ) {
		$out = array( 'row' => null );
		foreach ( $meta_keys as $key ) {
			$out[ 'meta:' . $key ] = null;
		}
		foreach ( $taxonomies as $taxonomy ) {
			$out[ 'terms:' . $taxonomy ] = null;
		}
		if ( ! is_array( $current ) ) {
			return $out;
		}
		$out['row'] = self::row_hash( $current['row'] );
		$keys       = $meta_keys;
		foreach ( $current['meta'] as $row ) {
			$keys[] = $row['key'];
		}
		foreach ( array_unique( $keys ) as $key ) {
			$out[ 'meta:' . $key ] = self::meta_hash( self::meta_values( $current, $key ) );
		}
		$taxes = $taxonomies;
		foreach ( $current['terms'] as $term ) {
			$taxes[] = $term['taxonomy'];
		}
		foreach ( array_unique( $taxes ) as $taxonomy ) {
			$out[ 'terms:' . $taxonomy ] = self::terms_hash( self::term_keys( $current, $taxonomy ) );
		}
		return $out;
	}

	/**
	 * The hash of one named part of a post as it is now: row, meta:<key> or
	 * terms:<taxonomy>, as hashes() names them; for no post, the hash of
	 * nothing there (an empty row set) where $current is null, so that what
	 * a write made of a part that was not there compares (the engine asks it
	 * after the row was written, Codex round 2 of branch 8, N2).
	 *
	 * @param array|null $current current(), null for no post.
	 * @param string     $part    The name of the part.
	 * @return string|null Null for the row of no post.
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
			return self::meta_hash( self::meta_values( $current, substr( $part, 5 ) ) );
		}
		return self::terms_hash( self::term_keys( $current, substr( $part, 6 ) ) );
	}

	/**
	 * @param array $columns The written columns of a row.
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
	 * @param string[] $values The values of a key, in the order of their rows.
	 * @return string
	 */
	public static function meta_hash( array $values ) {
		return AB_MCP_Sync_Canon::hash( array_values( array_map( 'strval', $values ) ) );
	}

	/**
	 * The hash of the terms of one taxonomy as a set of their keys (term
	 * ids, or slugs for IDENTITY_TAXONOMIES; term_keys()).
	 *
	 * @param array $keys Term keys of a taxonomy.
	 * @return string
	 */
	public static function terms_hash( array $keys ) {
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		sort( $keys, SORT_STRING );
		return AB_MCP_Sync_Canon::hash( $keys );
	}

	/**
	 * @param array  $current current().
	 * @param string $key     Key.
	 * @return string[]
	 */
	public static function meta_values( array $current, $key ) {
		$out = array();
		foreach ( $current['meta'] as $row ) {
			if ( $row['key'] === (string) $key ) {
				$out[] = $row['value'];
			}
		}
		return $out;
	}

	/**
	 * The keys of the terms of one taxonomy a post carries: their ids, or
	 * their slugs for a taxonomy of IDENTITY_TAXONOMIES (B6: a format term
	 * this site has not made yet has no id, and the one wp_set_object_terms()
	 * makes is never the one a target could name).
	 *
	 * @param array  $current  current().
	 * @param string $taxonomy Taxonomy.
	 * @return array int[] or string[].
	 */
	public static function term_keys( array $current, $taxonomy ) {
		$slugs = in_array( (string) $taxonomy, self::IDENTITY_TAXONOMIES, true );
		$out   = array();
		foreach ( $current['terms'] as $term ) {
			if ( $term['taxonomy'] === (string) $taxonomy ) {
				$out[] = $slugs ? (string) $term['slug'] : (int) $term['term_id'];
			}
		}
		return $out;
	}

	/**
	 * The keys of the terms a taxonomy of the target names: the ids as the
	 * pairs mapped them, the slugs of an identity taxonomy as they are.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $list     Ids or slugs (target_of()).
	 * @return array int[] or string[].
	 */
	public static function target_term_keys( $taxonomy, array $list ) {
		$slugs = in_array( (string) $taxonomy, self::IDENTITY_TAXONOMIES, true );
		$out   = array();
		foreach ( $list as $term ) {
			$out[] = $slugs ? (string) $term : (int) $term;
		}
		return $out;
	}

	/**
	 * The canonical hash of a post here, in this site's ID space, read from
	 * its rows as the inventory reads it.
	 *
	 * @param int   $live_id Post.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return string|null Null where it is not there or could not be read whole.
	 */
	public static function live_hash( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( 'post' );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => 'post',
				'ref'    => (string) (int) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) && isset( $read['hash'] ) && ! empty( $read['complete'] ) ? (string) $read['hash'] : null;
	}

	/* ----------------------------------------------------------- writing */

	/**
	 * The parts the row call writes besides the row, from the fields of its
	 * postarr: part => the hash the core was to write. This is the one place
	 * that says what a step writes without a call of its own (the ownership
	 * rule of the engine: a part is the step's own only where the step wrote
	 * it, through its own call or through the row, and reads back what that
	 * call was to write; Codex round 3 of branch 8). meta:_wp_page_template
	 * where postarr carries page_template: wp_insert_post() stores the
	 * template itself (B7), the hash of the template, which is the target
	 * of the part. terms:category for a post of the type post that has no
	 * category yet (a new one, or one here without any): wp_insert_post()
	 * gives it the default category of this site, the hash of its id (not
	 * the target, which the step's own terms call writes right after; a new
	 * post is never put back). Nothing else the core writes with the row is
	 * a part of a step: revisions, _wp_old_slug and _wp_old_date are no
	 * parts (AB_MCP_Sync_Exclusions), and an update passes the categories a
	 * post has back to wp_set_post_categories() unchanged.
	 *
	 * The row put back (restore_row(), wp_update_post() with the backed-up
	 * columns, no page_template) asks the same: type the backed-up type,
	 * postarr empty, $current the state read right before the put back; a
	 * post without a category at that moment gets the default one with the
	 * row, which the engine then owns for the put back (N3).
	 *
	 * @param array      $prepared target_of(): type and postarr; or type and an empty postarr for the row put back.
	 * @param int|null   $live_id  The post here, null for a new one.
	 * @param array|null $current  current() of the post here right before the row call; null for a new one.
	 * @return array<string,string> part => hash.
	 */
	public static function with_row( array $prepared, $live_id, $current = null ) {
		$out = array();
		if ( isset( $prepared['postarr']['page_template'] ) ) {
			$out['meta:_wp_page_template'] = self::meta_hash( array( (string) $prepared['postarr']['page_template'] ) );
		}
		if ( 'post' === (string) $prepared['type'] && ( null === $live_id || ! is_array( $current ) || array() === self::term_keys( $current, 'category' ) ) ) {
			$out['terms:category'] = self::terms_hash( array( (int) get_option( 'default_category' ) ) );
		}
		return $out;
	}

	/**
	 * Write the row: wp_insert_post() for a new post, wp_update_post() for
	 * one here, slashed, with both date columns and edit_date.
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The post here, null for a new one.
	 * @return int|WP_Error The id of the post written.
	 */
	public static function write_row( array $prepared, $live_id ) {
		$postarr = $prepared['postarr'];
		if ( null !== $live_id ) {
			$postarr['ID'] = (int) $live_id;
			$id            = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! is_int( $id ) || $id < 1 ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', 'WordPress wrote no post (wp_insert_post() gave no id). Nothing more of the step is written.' );
		}
		return $id;
	}

	/**
	 * Write the rows of one meta key: exactly the values given, in their
	 * order, through the metadata API with wp_slash(): one value both sides
	 * through update_metadata(), else the rows of the key removed and added
	 * anew; no value, the rows removed.
	 *
	 * The key is slashed as the values are: update_metadata(),
	 * add_metadata() and delete_metadata() unslash the key as well as the
	 * value (wp-includes/meta.php), so a key with a backslash handed raw
	 * would be stored, or removed, under another name (GitHub Codex round 1
	 * of branch 8). Every other place takes the key as it is: the reads from
	 * the rows (AB_MCP_Sync_Rows), the rights (map_meta_cap), the protected
	 * keys (is_protected_meta()) and the way back, which writes raw.
	 *
	 * @param int      $live_id Post.
	 * @param string   $key     Key.
	 * @param array    $values  The values to store (a text, or an array for a serialized one).
	 * @param string[] $current The values stored now.
	 * @return true|WP_Error
	 */
	public static function write_meta( $live_id, $key, array $values, array $current ) {
		$id = (int) $live_id;
		if ( 1 === count( $values ) && 1 === count( $current ) ) {
			$done = update_metadata( 'post', $id, wp_slash( (string) $key ), wp_slash( $values[0] ) );
			// false also where the value is the same already.
			return false === $done && self::meta_values_now( $id, $key ) !== array( self::stored_form( $values[0] ) ) ? self::meta_refused( $key ) : true;
		}
		if ( array() !== $current && false === delete_metadata( 'post', $id, wp_slash( (string) $key ) ) ) {
			return self::meta_refused( $key );
		}
		foreach ( $values as $value ) {
			if ( false === add_metadata( 'post', $id, wp_slash( (string) $key ), wp_slash( $value ) ) ) {
				return self::meta_refused( $key );
			}
		}
		return true;
	}

	/**
	 * Write the terms of one taxonomy: wp_set_object_terms() to exactly the
	 * ids (or slugs of post_format) given.
	 *
	 * @param int    $live_id  Post.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $terms    Ids or slugs.
	 * @return true|WP_Error
	 */
	public static function write_terms( $live_id, $taxonomy, array $terms ) {
		$done = wp_set_object_terms( (int) $live_id, $terms, (string) $taxonomy, false );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return is_array( $done ) ? true : new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The terms of %s could not be set (wp_set_object_terms() gave no list).', $taxonomy ) );
	}

	/**
	 * After the writes of a step (decision 33, phase 7 of the object): the
	 * caches of the post and its terms.
	 *
	 * @param int $live_id Post.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		clean_post_cache( (int) $live_id );
		wp_cache_delete( (int) $live_id, 'post_meta' );
		foreach ( self::TAXONOMIES as $taxonomy ) {
			wp_cache_delete( (int) $live_id, $taxonomy . '_relationships' );
		}
		$done = array( 'post', 'post_meta', 'term_relationships' );
		if ( class_exists( 'AB_MCP_Builder_Caches' ) ) {
			$done = array_merge( $done, (array) AB_MCP_Builder_Caches::clear( (int) $live_id ) );
		}
		if ( class_exists( 'AB_MCP_Page_Cache' ) ) {
			$purged = AB_MCP_Page_Cache::purge_posts( array( (int) $live_id ) );
			if ( is_array( $purged ) ) {
				$done[] = 'page_cache';
			}
		}
		return $done;
	}

	/* ---------------------------------------------------------- way back */

	/**
	 * Put the row back from its backup (decision 23): only where the row
	 * now hashes to what the step read back ($written); through
	 * wp_update_post() with the backed-up columns and edit_date, after the
	 * check of the status WordPress would store now (a backed-up future
	 * whose date passed, or nearer than the lead, is left: «check needed»).
	 *
	 * @param int    $live_id Post.
	 * @param array  $backup  The backed-up columns.
	 * @param string $before  The hash of the backup.
	 * @param string $written The hash the step read back.
	 * @param int    $now     Time.
	 * @return array{state:string,note?:string}|WP_Error state restored, unchanged, left or check_needed.
	 */
	public static function restore_row( $live_id, array $backup, $before, $written, $now ) {
		$current = self::current( (int) $live_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current ) {
			return array(
				'state' => 'left',
				'note'  => 'The post is no longer on this site.',
			);
		}
		$hash = self::row_hash( $current['row'] );
		if ( hash_equals( $before, $hash ) ) {
			return array( 'state' => 'unchanged' );
		}
		if ( ! hash_equals( $written, $hash ) ) {
			return array(
				'state' => 'left',
				'note'  => 'The row was changed since the step wrote it, so it is left as it is.',
			);
		}
		$gmt   = $backup['post_date_gmt'];
		$saved = AB_MCP_Tools_Content::saved_status( $backup['post_type'], $backup['post_status'], $gmt, (int) $now );
		if ( $saved !== $backup['post_status'] || '' !== AB_MCP_Tools_Content::status_date_refusal( $backup['post_type'], $current['row']['post_status'], $backup['post_status'], $gmt, true, (int) $now ) ) {
			return array(
				'state' => 'check_needed',
				'note'  => sprintf( 'Check needed: the backed-up status %1$s dated %2$s UTC would be stored as %3$s now (the scheduled date passed). Set the date anew in wp-admin.', $backup['post_status'], $gmt, $saved ),
			);
		}
		// Putting a page of the top level back to publish or future while a
		// menu of this site adds pages by itself (decisions 21 and 23): the
		// automatic would add it (now, or by the cron for future), unplanned
		// and unjournaled, so the row is left and named (A3).
		if ( 'page' === $backup['post_type'] && 0 === (int) $backup['post_parent'] && in_array( $backup['post_status'], array( 'publish', 'future' ), true ) && $backup['post_status'] !== $current['row']['post_status'] && self::menu_adds_pages() ) {
			return array(
				'state' => 'check_needed',
				'note'  => sprintf( 'Check needed: putting the page back to %s at the top level would make a menu of this site add it by itself (automatic page addition). Switch the automatic off under Appearance → Menus and plan the way back again, or set the status by hand in wp-admin.', $backup['post_status'] ),
			);
		}
		$postarr = array_intersect_key( $backup, array_flip( self::WRITTEN_COLUMNS ) );
		$postarr['ID']          = (int) $live_id;
		$postarr['post_author'] = (int) $postarr['post_author'];
		$postarr['post_parent'] = (int) $postarr['post_parent'];
		$postarr['menu_order']  = (int) $postarr['menu_order'];
		$postarr['edit_date']   = true;
		$id                     = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$after = self::current( (int) $live_id );
		if ( ! is_array( $after ) || ! hash_equals( $before, self::row_hash( $after['row'] ) ) ) {
			return array(
				'state' => 'unclear',
				'note'  => 'The row was written back but does not read as the backup.',
			);
		}
		return array( 'state' => 'restored' );
	}

	/**
	 * Put the rows of a meta key back raw, byte for byte conditional
	 * (decision 23): where the values now hash to what the step read back,
	 * each row the step changed is written back only where its bytes are
	 * still what the step wrote (CAST AS BINARY and OCTET_LENGTH), rows the
	 * step added are removed only with those bytes, rows the step removed
	 * are inserted only where the key has fewer rows than the backup.
	 *
	 * @param int      $live_id Post.
	 * @param string   $key     Key.
	 * @param string[] $backup  The values before the step, in order.
	 * @param string   $written The hash of the values the step read back (the journal).
	 * @return array{state:string,note?:string}|WP_Error state restored, unchanged or left.
	 */
	public static function restore_meta( $live_id, $key, array $backup, $written ) {
		$id  = (int) $live_id;
		$now = self::meta_values_now( $id, $key );
		if ( null === $now ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The meta rows of %s could not be read, so nothing of them is put back. Try again; if it keeps failing, the database error log names the cause.', $key ) );
		}
		if ( self::meta_hash( $now ) === self::meta_hash( $backup ) ) {
			return array( 'state' => 'unchanged' );
		}
		if ( ! hash_equals( (string) $written, self::meta_hash( $now ) ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The meta %s was changed since the step wrote it, so it is left as it is.', $key ),
			);
		}
		global $wpdb;
		$rows = AB_MCP_Sync_Rows::meta( 'post', $id );
		if ( null === $rows ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The meta rows of %s could not be read, so nothing of them is put back. Try again; if it keeps failing, the database error log names the cause.', $key ) );
		}
		$own = array();
		foreach ( $rows as $row ) {
			if ( $row['key'] === (string) $key ) {
				$own[] = $row;
			}
		}
		// Rows that are there and have a counterpart in the backup are written
		// back; the rest are removed; what the backup has more is inserted.
		$count = min( count( $own ), count( $backup ) );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $own[ $i ]['value'] === $backup[ $i ] ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- byte for byte conditional, the point of the way back.
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND CAST( meta_value AS BINARY ) = CAST( %s AS BINARY ) AND OCTET_LENGTH( meta_value ) = %d", $backup[ $i ], (int) $own[ $i ]['meta_id'], $id, $own[ $i ]['value'], strlen( $own[ $i ]['value'] ) ) );
			if ( 1 !== (int) $done ) {
				wp_cache_delete( $id, 'post_meta' );
				return array(
					'state' => 'left',
					'note'  => sprintf( 'A row of the meta %s changed while it was put back, so the rest is left as it is.', $key ),
				);
			}
		}
		for ( $i = $count; $i < count( $own ); $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- only with the bytes the step wrote.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_id = %d AND post_id = %d AND CAST( meta_value AS BINARY ) = CAST( %s AS BINARY ) AND OCTET_LENGTH( meta_value ) = %d", (int) $own[ $i ]['meta_id'], $id, $own[ $i ]['value'], strlen( $own[ $i ]['value'] ) ) );
		}
		for ( $i = $count; $i < count( $backup ); $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the raw row the step removed, as it was.
			$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => (string) $key, 'meta_value' => $backup[ $i ] ), array( '%d', '%s', '%s' ) );
		}
		wp_cache_delete( $id, 'post_meta' );
		$after = self::meta_values_now( $id, $key );
		return array( 'state' => is_array( $after ) && self::meta_hash( $after ) === self::meta_hash( $backup ) ? 'restored' : 'unclear' );
	}

	/**
	 * Put the terms of a taxonomy back (decision 23): where the set now is
	 * the one the step wrote, wp_set_object_terms() to the backed-up ids.
	 *
	 * The set is read now, as it is at the moment of the call, never from a
	 * state read before another part went back: the row goes back before the
	 * terms, and wp_update_post() has side effects on them (the default
	 * category of a post without one, with_row()); the engine decides the
	 * terms afresh after the row went back (put_back(), N3) and names here
	 * what they have to hold ($written).
	 *
	 * @param int    $live_id  Post.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $backup   Term keys before the step (term_keys()).
	 * @param string $written  The hash the term keys have to hold now: what the step, or the row put back, wrote (the journal).
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_terms( $live_id, $taxonomy, array $backup, $written ) {
		$current = self::current( (int) $live_id );
		if ( is_wp_error( $current ) || null === $current ) {
			return is_wp_error( $current ) ? $current : array(
				'state' => 'left',
				'note'  => 'The post is no longer on this site.',
			);
		}
		$now = self::terms_hash( self::term_keys( $current, $taxonomy ) );
		if ( $now === self::terms_hash( $backup ) ) {
			return array( 'state' => 'unchanged' );
		}
		if ( ! hash_equals( (string) $written, $now ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The terms of %s were changed since the step wrote them, so they are left as they are.', $taxonomy ),
			);
		}
		$done = wp_set_object_terms( (int) $live_id, self::target_term_keys( $taxonomy, $backup ), (string) $taxonomy, false );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$after = self::current( (int) $live_id );
		return array( 'state' => is_array( $after ) && self::terms_hash( self::term_keys( $after, $taxonomy ) ) === self::terms_hash( $backup ) ? 'restored' : 'unclear' );
	}

	/* ------------------------------------------------- the value before */

	/**
	 * What a part held before the step, from the backup, for a part the
	 * answer names as left (the decision of 07.11.2026, «A mit altem Wert»):
	 * short and readable, never a credential. The row: the columns that
	 * differ from the row as it is now (or NAMED_COLUMNS where the row is
	 * gone), each with its value in quotes, cut at VALUE_CHARS; a long text
	 * (LONG_COLUMNS) by its bytes, with the id of the revision of the post
	 * that holds it byte for byte where one does (a raw row,
	 * AB_MCP_Sync_Rows::revision_with()). Meta: the values of the key in the
	 * order of their rows. Terms: the names of the terms of the taxonomy as
	 * this site has them now (a raw row, AB_MCP_Sync_Rows::term(); their
	 * slug where the term is gone). A value a credential check names (AB_MCP_Sync_Credentials::
	 * unit() on the key and the value, as the package is checked; the column
	 * post_password by its name) is shown as a reference only, never in the
	 * clear; a value that is no UTF-8 by its bytes. The whole is cut at
	 * PART_CHARS with the mark «(cut)»; AB_MCP_Sync_Run::shown() caps what
	 * one answer carries of these values.
	 *
	 * @param string     $part    Part (row, meta:<key>, terms:<taxonomy>).
	 * @param array|null $backup  current() of the backup; null for a new object.
	 * @param array|null $now     current() as read now; null where unknown or gone.
	 * @param int|null   $live_id Post, for its revisions.
	 * @return string
	 */
	public static function before_value( $part, $backup, $now, $live_id ) {
		if ( ! is_array( $backup ) ) {
			return 'none: the object was not on this site before the run.';
		}
		$part = (string) $part;
		if ( 'row' === $part ) {
			$text = self::row_before( $backup['row'], is_array( $now ) ? $now['row'] : null, $live_id );
		} elseif ( 0 === strpos( $part, 'meta:' ) ) {
			$text = self::meta_before( substr( $part, 5 ), self::meta_values( $backup, substr( $part, 5 ) ) );
		} else {
			$text = self::terms_before( substr( $part, 6 ), $backup );
		}
		return self::cut( $text, self::PART_CHARS );
	}

	/**
	 * @param array      $backup  The backed-up columns.
	 * @param array|null $now     The columns now; null where the row is gone.
	 * @param int|null   $live_id Post.
	 * @return string
	 */
	private static function row_before( array $backup, $now, $live_id ) {
		$columns = array();
		foreach ( self::WRITTEN_COLUMNS as $column ) {
			$was = isset( $backup[ $column ] ) ? (string) $backup[ $column ] : '';
			if ( null === $now ? in_array( $column, self::NAMED_COLUMNS, true ) : $was !== ( isset( $now[ $column ] ) ? (string) $now[ $column ] : '' ) ) {
				$columns[ $column ] = $was;
			}
		}
		if ( array() === $columns ) {
			foreach ( self::NAMED_COLUMNS as $column ) {
				$columns[ $column ] = isset( $backup[ $column ] ) ? (string) $backup[ $column ] : '';
			}
		}
		$out = array();
		foreach ( $columns as $column => $was ) {
			if ( 'post_password' === $column || AB_MCP_Sync_Credentials::names_credential( $column, AB_MCP_Redactor::extra_keys() ) ) {
				$out[] = $column . ': ' . ( '' === $was ? '""' : '(a credential by its name, not shown)' );
			} elseif ( in_array( $column, self::LONG_COLUMNS, true ) ) {
				$out[] = $column . ': ' . self::long_before( $column, $was, $live_id );
			} else {
				$out[] = $column . ': ' . self::shown_value( '', $was );
			}
		}
		return implode( '; ', $out );
	}

	/**
	 * A long text by its bytes, with the revision of the post that holds it
	 * byte for byte where one does (the revisions WordPress keeps of a post:
	 * post_content, post_excerpt), else the backup of the step.
	 *
	 * @param string   $column  Column.
	 * @param string   $was     The text before.
	 * @param int|null $live_id Post.
	 * @return string
	 */
	private static function long_before( $column, $was, $live_id ) {
		$bytes = strlen( $was );
		if ( 0 === $bytes ) {
			return '""';
		}
		$held = null;
		if ( in_array( $column, array( 'post_content', 'post_excerpt' ), true ) && null !== $live_id ) {
			$found = AB_MCP_Sync_Rows::revision_with( (int) $live_id, $column, $was );
			$held  = is_int( $found ) && $found > 0 ? $found : null;
		}
		return sprintf( '%1$d bytes (%2$s)', $bytes, null === $held ? 'no revision of the post holds it; the backup of the step does' : sprintf( 'revision %d of the post holds it, wp-admin → Revisions', $held ) );
	}

	/**
	 * @param string   $key    Key.
	 * @param string[] $values The values before, in the order of their rows.
	 * @return string
	 */
	private static function meta_before( $key, array $values ) {
		if ( array() === $values ) {
			return 'none (no row of the key)';
		}
		$out = array();
		foreach ( $values as $value ) {
			$out[] = self::shown_value( $key, $value );
		}
		return implode( ', ', $out );
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @param array  $backup   current() of the backup.
	 * @return string
	 */
	private static function terms_before( $taxonomy, array $backup ) {
		$out = array();
		foreach ( $backup['terms'] as $term ) {
			if ( $term['taxonomy'] !== (string) $taxonomy ) {
				continue;
			}
			// The slug the backup holds, never the name the term has now: a
			// name changed since is no value before (Codex round 8).
			$out[] = self::shown_value( '', (string) $term['slug'] );
		}
		return array() === $out ? 'none (no term of the taxonomy)' : implode( ', ', $out );
	}

	/**
	 * A value before as an answer shows it, checked once more as it leaves
	 * (a journal of an earlier build may hold one composed by other rules,
	 * Codex round 9): where the credential check or the net names a
	 * credential in the text, only the reference.
	 *
	 * @param string   $text  value_before as stored.
	 * @param int|null $rules value_rules as stored: VALUE_RULES where this build composed it.
	 * @return string
	 */
	public static function checked_before( $text, $rules = null ) {
		if ( self::VALUE_RULES !== $rules ) {
			// Composed by an earlier build, by rules this one does not vouch
			// for (Codex round 9): never shown, fail closed.
			return '(composed by an earlier build, not shown; the backup of the step in the protected store holds the value)';
		}
		$text  = (string) $text;
		$extra = AB_MCP_Redactor::extra_keys();
		$check = AB_MCP_Sync_Credentials::unit( '', $text, $extra );
		if ( 'clean' !== $check['state'] || in_array( AB_MCP_Sync_Net::over_name( $text, $extra ), array( 'credential', 'unreadable' ), true ) ) {
			return '(holds a credential, not shown)';
		}
		return $text;
	}

	/**
	 * One value as the answer shows it: in quotes, cut at VALUE_CHARS; a
	 * reference only where the credential check names the key or a field
	 * inside the value, or cannot read the value to its end (fail closed);
	 * by its bytes where it is no UTF-8.
	 *
	 * @param string $key   The key the value belongs to ('' for none).
	 * @param string $value The value.
	 * @return string
	 */
	private static function shown_value( $key, $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '""';
		}
		$extra = AB_MCP_Redactor::extra_keys();
		$check = AB_MCP_Sync_Credentials::unit( (string) $key, $value, $extra );
		if ( 'clean' !== $check['state'] ) {
			return 'b9' === $check['state'] ? '(a credential by its name, not shown)' : ( 'mixed' === $check['state'] ? '(holds a credential, not shown)' : '(not readable to its end, not shown)' );
		}
		// The net over the raw value in every layer, as the package checks a
		// name (AB_MCP_Sync_Import::held_code(); Codex round 8).
		$net = AB_MCP_Sync_Net::over_name( $value, $extra );
		if ( 'credential' === $net ) {
			return '(holds a credential, not shown)';
		}
		if ( 'unreadable' === $net ) {
			return '(not readable to its end, not shown)';
		}
		if ( 1 !== preg_match( '//u', $value ) ) {
			return sprintf( '(%d bytes, not UTF-8)', strlen( $value ) );
		}
		return '"' . self::cut( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $value ), self::VALUE_CHARS ) . '"';
	}

	/**
	 * @param string $text UTF-8 text.
	 * @param int    $max  Characters.
	 * @return string The text, cut with the mark where longer.
	 */
	private static function cut( $text, $max ) {
		$text = (string) $text;
		if ( 1 !== preg_match( '//u', $text ) ) {
			return sprintf( '(%d bytes, not UTF-8)', strlen( $text ) );
		}
		if ( mb_strlen( $text, 'UTF-8' ) <= (int) $max ) {
			return $text;
		}
		return mb_substr( $text, 0, (int) $max, 'UTF-8' ) . '…(cut)';
	}

	/* ----------------------------------------------------------- helpers */

	/**
	 * Whether a meta key is one this version writes: a core key with an API
	 * of its own, or a plain key that is neither protected, nor the name of
	 * a credential, nor the markup of a page builder.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function meta_allowed( $key ) {
		$key = (string) $key;
		if ( in_array( $key, self::CORE_META, true ) ) {
			return true;
		}
		if ( '' === $key || '_' === $key[0] || is_protected_meta( $key, 'post' ) || AB_MCP_Sync_Credentials::names_credential( $key, AB_MCP_Redactor::extra_keys() ) ) {
			return false;
		}
		return ! AB_MCP_Builders::is_markup_meta_key( $key );
	}

	/**
	 * The value of a meta row as the metadata API stores it back to the
	 * same bytes: a serialized array unpacked (update_metadata() serializes
	 * it again, the same way), a text with its addresses rewritten; a
	 * serialized value that is no array (a scalar, an object) is refused:
	 * maybe_serialize() would serialize it once more.
	 *
	 * @param string $raw     As stored on the staging site.
	 * @param array  $plan    AB_MCP_Sync_Refs::plan(), collecting missing.
	 * @param bool   $changed Set when the addresses changed.
	 * @return string|array|WP_Error
	 */
	private static function meta_value( $raw, array &$plan, &$changed ) {
		if ( is_serialized( $raw ) ) {
			$read = AB_MCP_Sync_Canon::unserialized( $raw );
			if ( ! $read['ok'] || ! is_array( $read['value'] ) ) {
				return self::locked( 'post:meta_rule', 'a serialized value that is no array is not written through the metadata API as it is' );
			}
			$text = AB_MCP_Sync_Refs::rewrite_value( $raw, $plan, $changed );
			$read = AB_MCP_Sync_Canon::unserialized( $text );
			return $read['ok'] && is_array( $read['value'] ) ? $read['value'] : self::locked( 'post:meta_rule', 'a serialized value that does not unpack after its addresses were rewritten' );
		}
		return AB_MCP_Sync_Refs::rewrite_value( $raw, $plan, $changed );
	}

	/**
	 * The bytes the metadata API stores for a value handed to it.
	 *
	 * @param string|array $value Value.
	 * @return string
	 */
	private static function stored_form( $value ) {
		return is_array( $value ) ? serialize( $value ) : (string) $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as maybe_serialize() stores an array.
	}

	/**
	 * @param int    $id  Post.
	 * @param string $key Key.
	 * @return string[]|null The values now, by row.
	 */
	private static function meta_values_now( $id, $key ) {
		$rows = AB_MCP_Sync_Rows::meta( 'post', (int) $id );
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( $row['key'] === (string) $key ) {
				$out[] = $row['value'];
			}
		}
		return $out;
	}

	/**
	 * The login of the user a reference of the line names at a place.
	 *
	 * @param array  $line Line.
	 * @param string $at   Where (post_author).
	 * @return string|null
	 */
	private static function login_of( array $line, $at ) {
		foreach ( isset( $line['refs'] ) && is_array( $line['refs'] ) ? $line['refs'] : array() as $ref ) {
			if ( 'user' === ( $ref['space'] ?? null ) && (string) $at === (string) ( $ref['at'] ?? '' ) && isset( $ref['login'] ) && is_string( $ref['login'] ) && '' !== $ref['login'] ) {
				return $ref['login'];
			}
		}
		return null;
	}

	/**
	 * @param string $login Login.
	 * @param array  $ctx   Context, with users (login => id) as a cache.
	 * @return int|null
	 */
	private static function user_by_login( $login, array $ctx ) {
		if ( isset( $ctx['users'][ $login ] ) ) {
			return (int) $ctx['users'][ $login ];
		}
		$user = get_user_by( 'login', $login );
		return is_object( $user ) && isset( $user->ID ) && (int) $user->ID > 0 ? (int) $user->ID : null;
	}

	/**
	 * Whether a menu of this site adds pages of the top level by itself
	 * (_wp_auto_add_pages_to_menu(): the option nav_menu_options, read from
	 * its row).
	 *
	 * @return bool
	 */
	public static function menu_adds_pages() {
		$row = AB_MCP_Sync_Rows::option( 'nav_menu_options' );
		if ( ! is_array( $row ) ) {
			// Not there: no automatic; not readable: fail closed.
			return null === $row;
		}
		$read = AB_MCP_Sync_Canon::unserialized( $row['value'] );
		if ( ! $read['ok'] ) {
			return true;
		}
		return is_array( $read['value'] ) && ! empty( $read['value']['auto_add'] );
	}

	/**
	 * Whether a post of this type and parent with this slug is here (not in
	 * the trash is not asked: WordPress renames against every status).
	 *
	 * @param string $slug   Slug.
	 * @param string $type   Post type.
	 * @param int    $parent Parent.
	 * @return bool
	 */
	public static function slug_taken( $slug, $type, $parent ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the rows, as the comparison reads them.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_parent = %d LIMIT 1", (string) $slug, (string) $type, (int) $parent ) );
		return null !== $found && '' !== (string) $wpdb->last_error ? true : null !== $found;
	}

	/**
	 * A value of the package as bytes (the byte form of the canon decoded).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function bytes( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) && isset( $value['~b'] ) && is_string( $value['~b'] ) ) {
			$bytes = base64_decode( $value['~b'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the byte form of the canonical form.
			return is_string( $bytes ) ? $bytes : '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The references without a counterpart that are not pending (B1).
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
	 * @param array[] $missing The references without a counterpart.
	 * @return string
	 */
	private static function missing_text( array $missing ) {
		$out = array();
		foreach ( array_slice( $missing, 0, 5 ) as $m ) {
			$out[] = (string) ( $m['space'] ?? '?' ) . ' ' . (int) ( $m['id'] ?? 0 );
		}
		return 'references without a counterpart on this site: ' . implode( ', ', $out );
	}

	/**
	 * A refusal with the lock of its stage (AB_MCP_Sync_Kinds::LOCKS).
	 *
	 * @param string $key    Lock key (post:…).
	 * @param string $detail What was found.
	 * @return WP_Error
	 */
	public static function locked( $key, $detail ) {
		$parts = explode( ':', $key, 2 );
		$lock  = AB_MCP_Sync_Kinds::lock( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
		if ( null === $lock ) {
			$lock = array(
				'key'    => $key,
				'stage'  => 'Z8d',
				'reason' => 'Not carried to its end in this version.',
			);
		}
		return new WP_Error(
			'ab_mcp_sync_locked',
			sprintf( 'Not written (%1$s, from %2$s): %3$s %4$s Use the editor in wp-admin to take this object over by hand, or leave it out of the plan.', $lock['key'], $lock['stage'], $lock['reason'], ucfirst( (string) $detail ) . '.' ),
			array( 'lock' => $lock + array( 'at' => (string) $detail ) )
		);
	}

	/**
	 * @param string $what What the account may not do.
	 * @return WP_Error
	 */
	private static function rights( $what ) {
		return new WP_Error( 'ab_mcp_sync_rights', sprintf( 'Not written: this account may not %s on this site. Connect with an account that may, or leave the object out of the plan.', $what ), array( 'needs' => $what ) );
	}

	/**
	 * @param string $key Key.
	 * @return WP_Error
	 */
	private static function meta_refused( $key ) {
		return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The meta %s was not written: a filter of this site refused it, or the database did. Nothing more of the step is written; ask the site administrator which plugin holds it.', $key ) );
	}
}
