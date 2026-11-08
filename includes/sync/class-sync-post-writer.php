<?php
/**
 * The write side of the kind post (contract A of the plan of 01.11.2026;
 * Z8 branches 8 and 9, decisions 17, 19, 21, 23, 28, 30 and 31): what a step
 * of a run writes for one post or page, how it is backed up, read back and
 * put back. The kind term has its own (AB_MCP_Sync_Term_Writer) with the
 * same methods; the engine picks by the kind (AB_MCP_Sync_Engine::WRITERS).
 *
 * WHAT IS WRITTEN (TYPES, TAXONOMIES, CORE_META): a post or page (its row:
 * status and dates, title, content, excerpt, slug, parent, menu order,
 * author, comment and ping status, mime type), its terms of category,
 * post_tag and post_format; since branch 9b the other core types of the
 * kind post (decision 25): a template, a template part and the global
 * styles of the active theme (THEMED: their theme, the term of wp_theme,
 * is the active stylesheet of this site, else post:theme_mismatch; the
 * area of a template part, the term of wp_template_part_area, is a field
 * written by its slug, not part of the identity), a navigation (the one
 * WordPress shows where a navigation block has no menu of its own stays
 * the one it was, else post:navigation_fallback) and a reusable block
 * (synced or not, its pattern categories by their pairs); a template part
 * a content names by theme and slug (core/template-part) has to be one
 * this site shows (a row, a file of its theme, or one the run creates
 * first), else post:reference_unmapped; the custom CSS of the global
 * styles keeps the rule of the site editor (post:css_markup); and its meta by the field rule of decision 28
 * (branch 9): of a post here, each key whose values differ between the
 * target and this site, with the right of its operation through
 * map_meta_cap at the post (edit_, add_ or delete_post_meta; a key the map
 * refuses locks the post, *:meta_without_rule, «Feldregel folgt mit Z8d»),
 * a key equal on both sides untouched; the core keys with an API of their
 * own with edit_post (the template through page_template of the row, the
 * featured image through set_post_thumbnail() by the pair of its
 * attachment); a key of a page builder only for an account with
 * unfiltered_html; a key whose name is the name of a credential never (B9).
 * A new post takes the keys whose right follows the right at the post
 * itself (not protected, no authorization filter of their own; asked at the
 * post right before each call); any other locks it before it is made
 * (post:create_with_object_meta, Z8d). Refused with their lock before
 * anything is written (AB_MCP_Sync_Kinds::LOCKS): another core type, a
 * status that is no target, a type the table locks (a changeset, decision
 * 30), what the canonical form of either side locks (a page builder that is
 * not released, decision 31), a term of another taxonomy, a slug WordPress
 * would store otherwise (wp_unique_post_slug(), a post in the trash holding
 * it), a field or value WordPress's own sanitizing would change (kses
 * without unfiltered_html, sanitize_title(), sanitize_meta()), an author
 * without an account here, a reference without a counterpart, a status
 * WordPress would store otherwise (a scheduled date that passed); a page of
 * the top level that would be published or scheduled while a menu of this
 * site adds pages by itself is refused unless the person confirmed the
 * choice «as draft» (decision 21). Nothing is deleted.
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
require_once __DIR__ . '/class-sync-identity.php';
require_once __DIR__ . '/class-sync-credentials.php';
require_once __DIR__ . '/class-sync-write.php';
require_once dirname( __DIR__ ) . '/tools/class-tools-content.php';
require_once dirname( __DIR__ ) . '/builders/class-builders.php';
require_once dirname( __DIR__ ) . '/class-redactor.php';

/**
 * Class AB_MCP_Sync_Post_Writer
 */
final class AB_MCP_Sync_Post_Writer {

	/**
	 * The post types this version writes: posts and pages (branches 8 and
	 * 9) and the other core types of the kind post (branch 9b of Z8, the
	 * decision of Thomas of 08.11.2026, «A: eigener Zweig 9b»; decision 25).
	 */
	const TYPES = array( 'post', 'page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );

	/**
	 * The types whose identity holds their theme (AB_MCP_Sync_Identity::
	 * THEMED: the term of wp_theme): WordPress finds them by the name of the
	 * active theme only (get_block_template(),
	 * WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles(),
	 * wp_filter_wp_template_unique_post_slug()), so they are written only
	 * where that theme is the active one here (theme_refusal()).
	 */
	const THEMED = AB_MCP_Sync_Identity::THEMED;

	/**
	 * The types any page of this site may show: a template, a template
	 * part, the global styles, a navigation, a reusable block. After a
	 * write the page cache of the whole site is emptied, not that of the
	 * object (post_write()).
	 */
	const SITE_WIDE = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );

	/**
	 * The taxonomies this version writes the terms of: those of posts
	 * (category, post_tag, post_format), the pattern categories of a
	 * reusable block, the theme of a themed type and the area of a
	 * template part (branch 9b).
	 */
	const TAXONOMIES = array( 'category', 'post_tag', 'post_format', 'wp_pattern_category', 'wp_theme', 'wp_template_part_area' );

	/**
	 * The taxonomies of IDENTITY_TAXONOMIES that WordPress reads by the
	 * name of their term, not its slug: the theme
	 * (_build_block_template_result_from_post() and the queries of the
	 * templates and the global styles by the field name) and the area of a
	 * template part (its name is the area). Written by the slug
	 * (wp_set_object_terms() makes a missing term with the slug as its
	 * name), so a term whose name is not its slug, on either side, waits
	 * for its field rule (post:taxonomy_rule).
	 */
	const NAMED_TAXONOMIES = array( 'wp_theme', 'wp_template_part_area' );

	/**
	 * The core meta keys with an API of their own (decision 28): the
	 * template through page_template of the row, the featured image through
	 * set_post_thumbnail() and delete_post_thumbnail(); both with the right
	 * edit_post, as the core asks it for them.
	 */
	const CORE_META = array( '_wp_page_template', '_thumbnail_id' );

	/**
	 * The taxonomies whose terms are part of the post that carries them, not
	 * term objects (AB_MCP_Sync_Kinds::OWNERSHIP, kind post; the canonical
	 * form names them by slug, AB_MCP_Sync_Db_Kind::terms_form()): compared
	 * and written by their slug, not by a term id (the term may not exist
	 * here yet; wp_set_object_terms() makes it; Codex round 1 of branch 8,
	 * B6). The format of a post; the theme and the area of a template
	 * (branch 9b).
	 */
	const IDENTITY_TAXONOMIES = array( 'post_format', 'wp_theme', 'wp_template_part_area' );

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

	/** Posts fallback_shown() reads at most; more is fail closed. */
	const FALLBACK_POSTS = 200;

	/** Levels of template parts and patterns fallback_shown() follows at most; deeper is fail closed. */
	const FALLBACK_DEPTH = 16;

	/** Contents fallback_shown() reads at most for one navigation; more is fail closed. */
	const FALLBACK_STEPS = 2000;

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
		$type   = isset( $line['identity']['post_type'] ) ? (string) $line['identity']['post_type'] : '';
		$answer = AB_MCP_Sync_Kinds::post_type( $type );
		if ( 'locked' === $answer['status'] ) {
			// A type the table locks (a changeset, decision 30; a font; a type
			// of a plugin): its own lock and stage (branch 9).
			return self::refusal(
				array(
					'key'    => (string) $answer['key'],
					'stage'  => (string) $answer['stage'],
					'reason' => (string) $answer['reason'],
				),
				sprintf( 'post type %s', $type )
			);
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			// A type of another kind (an attachment, a menu item, the
			// additional CSS) is never written by the engine of the kind post
			// (post:other_kind); since branch 9b every core type of the kind
			// post is one of TYPES.
			return self::locked( 'post:other_kind', sprintf( 'post type %s', $type ) );
		}
		// What the canonical form of the staging object found (a page builder
		// that is not released, decision 31; a value it cannot read; a
		// credential in a field): refused with that lock (branch 9).
		$held = self::line_lock( $line );
		if ( null !== $held ) {
			return $held;
		}
		if ( ! isset( $line['content']['row'], $line['content']['meta'], $line['content']['terms'], $line['form'] ) || ! is_array( $line['form'] ) ) {
			return self::locked( 'post:content_missing', 'the package carries no content of the object (a row of it could not be read on the staging site)' );
		}
		$row = array();
		foreach ( $line['content']['row'] as $column => $value ) {
			$row[ (string) $column ] = self::bytes( $value );
		}
		foreach ( self::WRITTEN_COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				return self::locked( 'post:content_missing', sprintf( 'the package names no column %s', $column ) );
			}
		}
		if ( '' !== $row['post_password'] ) {
			return self::locked( 'post:password_protected', 'post_password' );
		}
		$status = 'create' === $item['op'] || 'update' === $item['op'] ? $row['post_status'] : '';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			// The trash is a deletion (post:in_trash, with the deletions); an
			// auto-draft is no content, never taken over (post:auto_draft);
			// any other status waits for its field rule (post:status_rule).
			$key = 'trash' === $status ? 'post:in_trash' : ( 'auto-draft' === $status ? 'post:auto_draft' : 'post:status_rule' );
			return self::locked( $key, sprintf( 'post status %s', '' === $status ? '(none)' : $status ) );
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
		// The template parts the content names by theme and slug
		// (core/template-part; branch 9b): no id to map, so each has to be one
		// this site shows, or one the same run creates first.
		$held = self::parts_refusal( self::part_refs( $content['content'] ), isset( $ctx['pending_named'] ) && is_array( $ctx['pending_named'] ) ? $ctx['pending_named'] : array() );
		if ( null !== $held ) {
			return $held;
		}
		$texts = array();
		foreach ( array( 'post_title', 'post_excerpt', 'post_content_filtered' ) as $column ) {
			$texts[ $column ] = AB_MCP_Sync_Refs::rewrite_text( $row[ $column ], $plan, array(), $changed );
			$missing          = self::unresolved( $plan['missing'], $pending );
			if ( array() !== $missing ) {
				return self::locked( 'post:reference_unmapped', self::missing_text( $missing ) );
			}
		}
		// The meta by its field rule (decision 28): every key of the staging
		// post; which of them a step writes, and with which right, decides the
		// check at the post here (precheck(): only keys whose values differ);
		// the featured image by the pair of its attachment; a key whose name is
		// the name of a credential is never packed (B9).
		$meta              = array();
		$extra             = AB_MCP_Redactor::extra_keys();
		$thumbnail_pending = false;
		foreach ( $line['content']['meta'] as $pair ) {
			$key   = self::bytes( $pair[0] );
			$value = self::bytes( $pair[1] );
			if ( '' === $key || AB_MCP_Sync_Exclusions::post_meta( $key ) || AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {
				continue;
			}
			if ( 'create' === $item['op'] && ! self::new_meta_allowed( $key, $type ) ) {
				return self::locked( 'post:create_with_object_meta', sprintf( 'meta key %s (protected, or a plugin answers its right at the object only)', $key ) );
			}
			if ( '_thumbnail_id' === $key ) {
				// The featured image: the attachment by its pair (decision 25);
				// one the same run creates before this post is pending (branch
				// 10): the check before the first write takes it as mapped, the
				// boundary maps it by the pair its step made.
				$mapped = 1 === preg_match( '/^[1-9][0-9]*\z/', $value ) ? AB_MCP_Sync_Refs::mapped_id( $plan, 'post', (int) $value ) : null;
				$plan['missing'] = array();
				if ( null === $mapped && 1 === preg_match( '/^[1-9][0-9]*\z/', $value ) && isset( $pending['post'][ (int) $value ] ) ) {
					$thumbnail_pending = true;
					$mapped            = 0;
				}
				if ( null === $mapped || isset( $meta[ $key ] ) ) {
					return self::locked( 'post:reference_unmapped', sprintf( 'the featured image %s of the staging site', $value ) );
				}
				$meta[ $key ][] = (string) (int) $mapped;
				continue;
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
			// A relationship of the staging post with a term_order other than
			// 0: the target carries the order (its canonical form, the hash
			// of its terms), wp_set_object_terms() writes every relationship
			// with 0, so the object would deviate after its parts were
			// written. Locked before anything is written, as a relationship
			// of the post here (precheck(); GitHub Codex round 7 on free#137,
			// P2: before, the order was dropped here, the step wrote and the
			// object deviated by its hash).
			if ( isset( $term['term_order'] ) && 0 !== (int) $term['term_order'] ) {
				return self::locked( 'post:term_order', sprintf( 'the term %1$s of %2$s on the post of the staging site carries the term_order %3$d', self::bytes( $term['slug'] ), $taxonomy, (int) $term['term_order'] ) );
			}
			if ( in_array( $taxonomy, self::IDENTITY_TAXONOMIES, true ) ) {
				// The theme and the area are read by the name of their term
				// (NAMED_TAXONOMIES) and written by its slug: a term whose name
				// is not its slug would read back as another theme or area here.
				if ( in_array( $taxonomy, self::NAMED_TAXONOMIES, true ) && ( ! isset( $term['name'] ) || self::bytes( $term['name'] ) !== self::bytes( $term['slug'] ) ) ) {
					return self::locked( 'post:taxonomy_rule', sprintf( 'the term %1$s of %2$s on the post of the staging site is named %3$s, and WordPress reads it by its name', self::bytes( $term['slug'] ), $taxonomy, isset( $term['name'] ) ? self::bytes( $term['name'] ) : '(none)' ) );
				}
				$terms[ $taxonomy ][] = self::bytes( $term['slug'] );
				continue;
			}
			$mapped = AB_MCP_Sync_Refs::mapped_id( $plan, 'term', (int) $term['term_id'] );
			if ( null === $mapped && isset( $pending['term'][ (int) $term['term_id'] ] ) ) {
				// A term the same run creates before this post (a new post in a
				// new category, branch 9): pending for the check before the
				// first write, which asks the right of its taxonomy; the
				// boundary maps it by the pair its step made.
				$plan['missing']       = array();
				$terms[ $taxonomy ]    = isset( $terms[ $taxonomy ] ) ? $terms[ $taxonomy ] : array();
				continue;
			}
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
		// A template of a block theme the same run creates before this object
		// (a custom template of the active theme, branch 9b): pending for the
		// check before the first write, which cannot find it offered yet; the
		// boundary asks the theme with the template made.
		$template_pending = isset( $postarr['page_template'], $ctx['pending_named']['wp_template'] ) && is_array( $ctx['pending_named']['wp_template'] ) && isset( $ctx['pending_named']['wp_template'][ (string) $postarr['page_template'] ] );
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
			'type'             => $type,
			'status'           => $status,
			'postarr'          => $postarr,
			'columns'          => $columns,
			'meta'             => $meta,
			'terms'            => $terms,
			'target_form'      => $target,
			'target_hash'      => AB_MCP_Sync_Canon::hash( $target ),
			'identity'         => $line['identity'],
			'as_draft'         => ! empty( $item['as_draft'] ),
			'pending'          => $parent_pending,
			'template_pending' => $template_pending,
			// A featured image the same run creates first (branch 10): the
			// check before the first write cannot ask WordPress whether it
			// shows it yet; the boundary asks it with the image made.
			'thumbnail_pending' => $thumbnail_pending,
			// The objects of the run, for a navigation: where the run leaves a
			// navigation block without a menu of its own (fallback_shown()).
			'planned'          => 'wp_navigation' === $type && isset( $ctx['planned'] ) && is_array( $ctx['planned'] ) ? $ctx['planned'] : array(),
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
			return self::locked( 'post:type_unregistered', sprintf( 'the post type %s is not registered on this site', $type ) );
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
		$current = null;
		if ( null !== $live_id ) {
			$current = self::current( (int) $live_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
		}
		// The other core types of the kind post (branch 9b): the theme of a
		// template, a template part or the global styles is the active one
		// here (theme_refusal()); the terms WordPress reads by their names
		// carry their slugs as names here as well (named_refusal()); the
		// custom CSS of the global styles keeps the rule of the site editor
		// (css_refusal()); a navigation leaves the one WordPress shows in a
		// navigation block without a menu of its own as it was
		// (fallback_refusal()).
		if ( in_array( $type, self::THEMED, true ) ) {
			$refusal = self::theme_refusal( $prepared, is_array( $current ) ? $current : null );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		$refusal = self::named_refusal( $prepared );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( 'wp_global_styles' === $type ) {
			$refusal = self::css_refusal( (string) $prepared['postarr']['post_content'] );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		if ( 'wp_navigation' === $type ) {
			$moved = self::fallback_refusal(
				null === $live_id ? 0 : (int) $live_id,
				is_array( $live ) && 'publish' === (string) $live['post_status'] ? (string) $live['post_date'] : null,
				'publish' === $status ? (string) $prepared['columns']['post_date'] : null,
				isset( $prepared['planned'] ) && is_array( $prepared['planned'] ) ? $prepared['planned'] : array()
			);
			if ( null !== $moved ) {
				return self::locked( 'post:navigation_fallback', $moved );
			}
		}
		// The meta by its field rule (decision 28; branch 9): of a post here,
		// each key whose values differ between the target and this site (a
		// key equal on both sides is no part and stays untouched), with the
		// right of its operation through map_meta_cap at the post (edit_,
		// add_ or delete_post_meta: the authorization filters of registered
		// meta and the lock of protected keys apply there); a key the map
		// refuses locks the post («Feldregel folgt mit Z8d»). The core keys
		// with an API of their own go with edit_post (asked above). A key of a
		// page builder needs unfiltered_html besides, as the free meta writer
		// asks it (AB_MCP_Builders::markup_meta_refusal()). Of a new post, every
		// key of the target (new_meta_allowed() in target_of(): the right
		// follows the right at the post the step makes, asked at it again
		// right before each call, write_part()).
		$keys = is_array( $current ) ? self::differing( $prepared, $current ) : array_keys( $prepared['meta'] );
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( in_array( $key, self::CORE_META, true ) ) {
				continue;
			}
			if ( is_array( $current ) ) {
				$may = self::meta_right( (int) $live_id, $key, self::targets( $prepared, $key ), self::meta_values( $current, $key ) );
				if ( true !== $may ) {
					return self::locked( '*:meta_without_rule', sprintf( 'meta key %1$s of the post on this site: %2$s', $key, $may ) );
				}
			}
			if ( null !== AB_MCP_Builders::markup_meta_refusal( $key ) ) {
				return self::rights( sprintf( 'write the page-builder meta key %s (unfiltered_html)', $key ) );
			}
		}
		// The featured image (decision 28): set_post_thumbnail() keeps the id
		// only where it is an attachment WordPress shows as an image, and
		// removes the featured image otherwise; so the attachment the pair
		// names has to be one.
		if ( in_array( '_thumbnail_id', $keys, true ) && isset( $prepared['meta']['_thumbnail_id'][0] ) && empty( $prepared['thumbnail_pending'] ) ) {
			$image = (int) $prepared['meta']['_thumbnail_id'][0];
			if ( 'attachment' !== AB_MCP_Sync_Rows::post_type_of( $image ) || '' === (string) wp_get_attachment_image( $image, 'thumbnail' ) ) {
				return self::locked( 'post:thumbnail', sprintf( 'the attachment %d of this site the featured image names is no image WordPress shows', $image ) );
			}
		}
		// Every written value through sanitize_meta() as update_metadata()
		// runs it (decision 17, the sanitizers ahead), compared by the bytes
		// WordPress would store (db_form()): a sanitizer that gives the same
		// bytes as another type (absint(): 5 for '5') changes nothing
		// (branch 9, Codex round 1, B3).
		foreach ( $keys as $key ) {
			foreach ( isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array() as $value ) {
				if ( self::db_form( sanitize_meta( (string) $key, $value, 'post', $type ) ) !== self::stored_form( $value ) ) {
					return self::locked( 'post:sanitizer', sprintf( 'meta key %s (sanitize_meta() changes its value)', $key ) );
				}
			}
		}
		// The template the theme of this site offers for the type (B7): as
		// wp_insert_post() checks page_template (WP_Theme::get_page_templates());
		// one it does not offer would be stored as default. A custom template
		// of a block theme the same run creates first is pending (branch 9b;
		// the boundary asks it with the template made).
		if ( isset( $prepared['postarr']['page_template'] ) && 'default' !== $prepared['postarr']['page_template'] && empty( $prepared['template_pending'] ) ) {
			if ( ! self::template_offered( (string) $prepared['postarr']['page_template'], $type ) ) {
				return self::locked( 'post:page_template', sprintf( 'the template %1$s for a %2$s', $prepared['postarr']['page_template'], $type ) );
			}
		}
		// The right to set the terms of every taxonomy the step touches
		// (decision 28; GitHub Codex round 2 of branch 8, P2): the taxonomies
		// of the target and those only the post of this site carries, which
		// the step clears (write_terms() with no term); for each the right
		// of the taxonomy to assign its terms, assign_term for every term by
		// its id or by the id its slug names here (post_format), and the
		// right to make a term for a slug this site has no term of (term_right()).
		$taxonomies = array_keys( $prepared['terms'] );
		foreach ( is_array( $current ) ? $current['terms'] : array() as $term ) {
			$taxonomies[] = (string) $term['taxonomy'];
		}
		foreach ( array_unique( $taxonomies ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return self::locked( 'post:taxonomy_rule', sprintf( 'the taxonomy %s is not registered on this site', $taxonomy ) );
			}
			$may = self::term_right( $taxonomy, isset( $prepared['terms'][ $taxonomy ] ) ? $prepared['terms'][ $taxonomy ] : array() );
			if ( true !== $may ) {
				return self::rights( $may );
			}
		}
		// A meta key the step writes or removes whose rows the database holds
		// under another spelling as well (Foo and foo: the collation of
		// postmeta compares keys without regard to letter case, and
		// update_metadata() and delete_metadata() write with WHERE meta_key =
		// …, so a write of one would change the other; the same holds for
		// _wp_page_template, which the row writes through update_post_meta()):
		// locked before anything is written. The rows every spelling comes
		// back with are those AB_MCP_Undo_Meta::rows() reads, as the undo asks
		// them for the other meta writers (spelling_refusal(), which refuses
		// by the rights of the other key; here any other spelling locks, since
		// the journal and the way back tell keys byte for byte; GitHub Codex
		// round 6 on free#137, P2).
		if ( null !== $live_id ) {
			$spelled = $keys;
			if ( isset( $prepared['postarr']['page_template'] ) ) {
				$spelled[] = '_wp_page_template';
			}
			$refusal = self::spelling_refusal( (int) $live_id, $spelled );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		// A relationship of the post here with a term_order other than 0 (a
		// plugin that sorts the terms): wp_set_object_terms() makes every
		// relationship anew with the order 0, forward and on the way back, so
		// the way back could not give the order back. Locked before anything
		// is written (GitHub Codex round 5 on free#137, P2); with every order
		// 0, as the core writes it, nothing changes.
		foreach ( is_array( $current ) ? $current['terms'] : array() as $term ) {
			if ( 0 !== (int) $term['term_order'] ) {
				return self::locked( 'post:term_order', sprintf( 'the term %1$s of %2$s on the post of this site carries the term_order %3$d', (string) $term['slug'], (string) $term['taxonomy'], (int) $term['term_order'] ) );
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
		// The slug (branch 9): what WordPress would store for it, as
		// wp_insert_post() makes it (slug_refusal()); with a pending parent
		// nothing is under it yet, the boundary checks it with the parent
		// mapped.
		if ( empty( $prepared['pending'] ) ) {
			$refusal = self::slug_refusal( $prepared, $live_id, is_array( $live ) ? (string) $live['post_name'] : null );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		// The sanitizers ahead (decision 17): the fields through
		// sanitize_post() as wp_insert_post() runs it, in the rights of this
		// account (kses without unfiltered_html), compared slashed.
		$fields    = array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered', 'comment_status', 'ping_status', 'post_mime_type' );
		$slashed   = wp_slash( array_intersect_key( $prepared['postarr'], array_flip( array_merge( $fields, array( 'post_name' ) ) ) ) );
		$sanitized = (array) sanitize_post( $slashed + array( 'post_type' => $type ), 'db' );
		foreach ( $fields as $field ) {
			if ( ! isset( $sanitized[ $field ] ) || (string) $sanitized[ $field ] !== (string) $slashed[ $field ] ) {
				return self::locked( 'post:sanitizer', sprintf( 'field %s', $field ) );
			}
		}
		return true;
	}

	/**
	 * A meta key the step writes or removes whose rows the database holds
	 * under another spelling as well (Foo and foo, precheck()), as a
	 * refusal, or null; for the kind attachment as well (branch 10).
	 *
	 * @param int      $live_id The post here.
	 * @param string[] $keys    The keys the step writes or removes.
	 * @return WP_Error|null
	 */
	public static function spelling_refusal( $live_id, array $keys ) {
		foreach ( array_unique( array_map( 'strval', $keys ) ) as $key ) {
			$rows = AB_MCP_Undo_Meta::rows( 'post', (int) $live_id, (string) $key );
			if ( null === $rows ) {
				return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The meta rows of the key %s of the post could not be read, so nothing is written for it. Try again; if it keeps failing, the database error log names the cause.', $key ) );
			}
			foreach ( $rows as $row ) {
				if ( $row['key'] !== (string) $key ) {
					return self::locked( 'post:meta_spelling', sprintf( 'the keys %1$s and %2$s of the post on this site are one key to the database (its collation compares them alike), and a write of one would change the other', (string) $key, (string) $row['key'] ) );
				}
			}
		}
		return null;
	}

	/**
	 * Whether this account may set the terms of a taxonomy on a post to
	 * exactly these keys (decision 28; GitHub Codex round 2 of branch 8,
	 * P2): the right of the taxonomy to assign its terms (its cap
	 * assign_terms) for the set, also where the set is emptied; for every
	 * term assign_term through map_meta_cap, by its id, or by the id of the
	 * term its slug names on this site (post_format, IDENTITY_TAXONOMIES);
	 * for a slug this site has no term of, the right of the taxonomy to make
	 * one (its cap edit_terms), as wp_set_object_terms() makes it (B6). True,
	 * or what the account may not do, for the refusal. The forward check
	 * (precheck()) and the way back (restore_right()) ask the same.
	 *
	 * @param string $taxonomy Taxonomy, registered on this site.
	 * @param array  $keys     Term ids, or the slugs of an identity taxonomy (target_of(), term_keys()).
	 * @return true|string
	 */
	public static function term_right( $taxonomy, array $keys ) {
		$taxonomy = (string) $taxonomy;
		$object   = get_taxonomy( $taxonomy );
		if ( ! is_object( $object ) || ! isset( $object->cap->assign_terms, $object->cap->edit_terms ) ) {
			return sprintf( 'assign the terms of %s (the taxonomy is not registered on this site, or names no right to assign its terms)', $taxonomy );
		}
		if ( ! current_user_can( (string) $object->cap->assign_terms ) ) {
			return sprintf( 'assign the terms of %1$s (%2$s)', $taxonomy, (string) $object->cap->assign_terms );
		}
		foreach ( self::target_term_keys( $taxonomy, $keys ) as $key ) {
			if ( is_int( $key ) ) {
				$id = $key;
			} else {
				$found = get_term_by( 'slug', (string) $key, $taxonomy );
				if ( ! is_object( $found ) || ! isset( $found->term_id ) ) {
					if ( ! current_user_can( (string) $object->cap->edit_terms ) ) {
						return sprintf( 'make the term %1$s of %2$s, which this site has no term of yet (%3$s)', (string) $key, $taxonomy, (string) $object->cap->edit_terms );
					}
					continue;
				}
				$id = (int) $found->term_id;
			}
			if ( ! current_user_can( 'assign_term', $id ) ) {
				return sprintf( 'assign the term %1$d of %2$s (assign_term)', $id, $taxonomy );
			}
		}
		return true;
	}

	/**
	 * Whether this account may put a part back as the way back writes it:
	 * the rights the forward write asks (precheck(); decision 28), asked
	 * again at the object right before the part goes back (GitHub Codex
	 * round 2 of branch 8, P2: the way back checked the owner of the run,
	 * never the rights at the object, and wp_update_post(), the raw SQL of
	 * restore_meta() and wp_set_object_terms() ask none). Every part is the
	 * post's: edit_post first, whatever the part (assign_term and the meta
	 * rights ask nothing of the post itself; Codex round 18 of branch 8: an
	 * account without edit_post put the terms back). Then the row:
	 * publish_posts where the backed-up status is publish, future or private
	 * and the row holds another now; edit_others_posts where the backed-up
	 * author is another account. A meta key: nothing more for the core key
	 * the row writes (_wp_page_template; CORE_META); a key of a page builder
	 * unfiltered_html (AB_MCP_Builders::markup_meta_refusal(), as forward);
	 * else edit_post_meta where rows are written over (both sides hold
	 * rows), add_post_meta where the backup holds more rows than the post
	 * now, delete_post_meta where it holds fewer, each through map_meta_cap.
	 * The terms of a taxonomy:
	 * term_right() for the backed-up keys. True, or what the account may
	 * not do.
	 *
	 * @param string $part    Part (row, meta:<key>, terms:<taxonomy>).
	 * @param int    $live_id Post.
	 * @param array  $backup  current() of the backup.
	 * @param array  $now     current() as read right before the part's turn.
	 * @return true|string
	 */
	public static function restore_right( $part, $live_id, array $backup, array $now ) {
		$part = (string) $part;
		$id   = (int) $live_id;
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return sprintf( 'edit the post %1$d (edit_post%2$s)', $id, 'row' === $part ? '' : ', for the part ' . $part );
		}
		if ( 'row' === $part ) {
			$type = isset( $backup['row']['post_type'] ) ? (string) $backup['row']['post_type'] : '';
			$pto  = get_post_type_object( $type );
			if ( ! is_object( $pto ) || ! isset( $pto->cap ) ) {
				return sprintf( 'write a %s (the post type is not registered on this site)', $type );
			}
			$status = isset( $backup['row']['post_status'] ) ? (string) $backup['row']['post_status'] : '';
			if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ( ! isset( $now['row']['post_status'] ) || (string) $now['row']['post_status'] !== $status ) && ! current_user_can( (string) $pto->cap->publish_posts ) ) {
				return sprintf( 'publish a %1$s (%2$s)', $type, (string) $pto->cap->publish_posts );
			}
			if ( isset( $backup['row']['post_author'] ) && (int) $backup['row']['post_author'] !== (int) get_current_user_id() && ! current_user_can( (string) $pto->cap->edit_others_posts ) ) {
				return sprintf( 'write a %1$s of another author (%2$s)', $type, (string) $pto->cap->edit_others_posts );
			}
			return true;
		}
		if ( 0 === strpos( $part, 'meta:' ) ) {
			$key = substr( $part, 5 );
			if ( in_array( $key, self::CORE_META, true ) ) {
				// The core key the row writes goes with edit_post, asked above.
				return true;
			}
			// A key of a page builder needs unfiltered_html besides, as the
			// forward write asks it (precheck()) and the free undo of meta
			// (AB_MCP_Undo_Meta): the account may have lost it since the run
			// wrote (DISALLOW_UNFILTERED_HTML; GitHub Codex round 1 on
			// free#138, P2), and restore_meta() writes raw.
			if ( null !== AB_MCP_Builders::markup_meta_refusal( $key ) ) {
				return sprintf( 'write the page-builder meta key %s of the post (unfiltered_html)', $key );
			}
			$was = count( self::meta_values( $backup, $key ) );
			$has = count( self::meta_values( $now, $key ) );
			if ( $was > 0 && $has > 0 && ! current_user_can( 'edit_post_meta', $id, $key ) ) {
				return sprintf( 'edit the meta key %s of the post (edit_post_meta)', $key );
			}
			if ( $was > $has && ! current_user_can( 'add_post_meta', $id, $key ) ) {
				return sprintf( 'add the meta key %s to the post (add_post_meta)', $key );
			}
			if ( $has > $was && ! current_user_can( 'delete_post_meta', $id, $key ) ) {
				return sprintf( 'remove the meta key %s of the post (delete_post_meta)', $key );
			}
			return true;
		}
		$taxonomy = substr( $part, 6 );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return sprintf( 'assign the terms of %s (the taxonomy is not registered on this site)', $taxonomy );
		}
		return self::term_right( $taxonomy, self::term_keys( $backup, $taxonomy ) );
	}

	/**
	 * Whether the post here is one this version writes as it is: its
	 * canonical form carries no lock, and no term of a taxonomy outside the
	 * rule, which would stay and make the written object differ from the
	 * target (fail closed). Its meta is the field rule's (precheck()).
	 *
	 * @param int   $live_id Post.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return true|WP_Error
	 */
	public static function live_rule( $live_id, array $source ) {
		$current = self::current( $live_id );
		if ( is_wp_error( $current ) || null === $current ) {
			return is_wp_error( $current ) ? $current : true;
		}
		// What the canonical form of the post here found (a page builder, a
		// value it cannot read, a credential in a field; branch 9): the report
		// holds the entry back on this side, the boundary checks it again.
		$held = self::read_lock( self::live_read( (int) $live_id, $source ), 'the post of this site' );
		if ( null !== $held ) {
			return $held;
		}
		foreach ( $current['terms'] as $term ) {
			if ( ! in_array( $term['taxonomy'], self::TAXONOMIES, true ) ) {
				return self::locked( 'post:taxonomy_rule', sprintf( 'taxonomy %s of the post on this site', $term['taxonomy'] ) );
			}
		}
		return true;
	}

	/**
	 * The parts of the journal of a step (decision 17, step 6, written
	 * ahead): the row, each meta key whose values differ between the target
	 * and this site (every key of the target for a new post), each taxonomy
	 * of the target and of this site, each with its way, its hash before (the
	 * backup) and its target.
	 *
	 * @param array      $prepared target_of().
	 * @param array|null $current  current() of the post here; null for a new one.
	 * @return array[] part, way, before, target.
	 */
	public static function journal_parts( array $prepared, $current ) {
		// The meta keys whose values differ (decision 28: a key equal on both
		// sides is no part and stays untouched; branch 9); every taxonomy of
		// the target and of this site, which the step sets or clears.
		$keys  = is_array( $current ) ? self::differing( $prepared, $current ) : array_keys( $prepared['meta'] );
		$taxes = array_keys( $prepared['terms'] );
		if ( is_array( $current ) ) {
			foreach ( $current['terms'] as $term ) {
				$taxes[] = $term['taxonomy'];
			}
		}
		$keys   = array_values( array_unique( $keys ) );
		$taxes  = array_values( array_unique( $taxes ) );
		$hashes = self::hashes( $current, $keys, $taxes );
		$out    = array(
			array(
				'part'   => 'row',
				'way'    => self::WAY_ROW,
				'before' => $hashes['row'],
				'target' => self::row_hash( $prepared['columns'] ),
			),
		);
		foreach ( $keys as $key ) {
			$stored = array();
			foreach ( isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array() as $value ) {
				$stored[] = self::stored_form( $value );
			}
			$out[] = array(
				'part'   => 'meta:' . $key,
				'way'    => self::WAY_META,
				'before' => $hashes[ 'meta:' . $key ],
				'target' => self::meta_hash( $stored ),
			);
		}
		foreach ( $taxes as $taxonomy ) {
			$out[] = array(
				'part'   => 'terms:' . $taxonomy,
				'way'    => self::WAY_TERMS,
				'before' => $hashes[ 'terms:' . $taxonomy ],
				'target' => self::terms_hash( isset( $prepared['terms'][ $taxonomy ] ) ? self::target_term_keys( $taxonomy, $prepared['terms'][ $taxonomy ] ) : array() ),
			);
		}
		return $out;
	}

	/**
	 * Whether a part is written by the row call, with no call of its own: the
	 * template through page_template (decision 17, B7), where the target has
	 * one; its removal goes through the meta API.
	 *
	 * @param array  $prepared target_of().
	 * @param string $name     The name of the part.
	 * @return bool
	 */
	public static function through_row( array $prepared, $name ) {
		return 'meta:_wp_page_template' === (string) $name && isset( $prepared['meta']['_wp_page_template'] ) && array() !== $prepared['meta']['_wp_page_template'];
	}

	/**
	 * The keys whose values differ between the target and the post here,
	 * sorted: those a step writes (decision 28). Never a key the comparison
	 * leaves out (AB_MCP_Sync_Exclusions, group B) or whose name is the name
	 * of a credential (B9: never packed, never compared, never written).
	 *
	 * @param array $prepared target_of().
	 * @param array $current  current().
	 * @return string[]
	 */
	public static function differing( array $prepared, array $current ) {
		$keys = array_map( 'strval', array_keys( $prepared['meta'] ) );
		foreach ( $current['meta'] as $row ) {
			$keys[] = (string) $row['key'];
		}
		$out   = array();
		$extra = AB_MCP_Redactor::extra_keys();
		foreach ( array_unique( $keys ) as $key ) {
			if ( AB_MCP_Sync_Exclusions::post_meta( $key ) || AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {
				continue;
			}
			if ( self::targets( $prepared, $key ) !== self::meta_values( $current, $key ) ) {
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
	public static function targets( array $prepared, $key ) {
		$out = array();
		foreach ( isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array() as $value ) {
			$out[] = self::stored_form( $value );
		}
		return $out;
	}

	/**
	 * The right to bring the rows of a key from these values to those, at
	 * the post, through map_meta_cap (decision 28): the right of every
	 * operation write_meta() makes for it (meta_ops()), no other.
	 *
	 * @param int      $live_id Post.
	 * @param string   $key     Key.
	 * @param string[] $to      The values to store.
	 * @param string[] $from    The values stored now.
	 * @return true|string What the account may not do.
	 */
	public static function meta_right( $live_id, $key, array $to, array $from ) {
		return self::ops_right( 'post', $live_id, $key, self::meta_ops( $to, $from ) );
	}

	/**
	 * The operations write_meta() makes to bring the rows of a key from
	 * these values to those, the one place that says them, for the write
	 * and for its rights (branch 9, Codex round 1, A2: the rights were asked
	 * by the net count of the rows, while a key of several rows is removed
	 * whole and added anew; a rule that gave edit but refused add and
	 * delete let two other values of two rows through): edit where one row
	 * stays one row (update_metadata()); else delete where rows are there
	 * (delete_metadata(), every row of the key) and add where values are to
	 * be stored (add_metadata(), each).
	 *
	 * @param array    $to   The values to store.
	 * @param string[] $from The values stored now.
	 * @return string[] Of edit, delete, add.
	 */
	public static function meta_ops( array $to, array $from ) {
		if ( 1 === count( $to ) && 1 === count( $from ) ) {
			return array( 'edit' );
		}
		$ops = array();
		if ( array() !== $from ) {
			$ops[] = 'delete';
		}
		if ( array() !== $to ) {
			$ops[] = 'add';
		}
		return $ops;
	}

	/**
	 * The operations restore_meta() makes to put the rows of a key back,
	 * row by row: edit where both sides hold rows (a row written back in
	 * place), add where the backup holds more rows than the object now (a
	 * row the step removed, inserted), delete where it holds fewer (a row
	 * the step added, removed).
	 *
	 * @param string[] $backup The values of the backup.
	 * @param string[] $now    The values stored now.
	 * @return string[] Of edit, add, delete.
	 */
	public static function restore_ops( array $backup, array $now ) {
		$ops = array();
		if ( array() !== $backup && array() !== $now ) {
			$ops[] = 'edit';
		}
		if ( count( $backup ) > count( $now ) ) {
			$ops[] = 'add';
		}
		if ( count( $backup ) < count( $now ) ) {
			$ops[] = 'delete';
		}
		return $ops;
	}

	/**
	 * Whether the account may make each of these operations on a key at the
	 * object, through map_meta_cap ({op}_{type}_meta, with the key).
	 *
	 * @param string   $type    The meta type: post, or term.
	 * @param int      $live_id The object.
	 * @param string   $key     Key.
	 * @param string[] $ops     Of edit, add, delete.
	 * @return true|string The first capability refused, as "<cap> refused".
	 */
	public static function ops_right( $type, $live_id, $key, array $ops ) {
		foreach ( $ops as $op ) {
			$cap = $op . '_' . ( 'term' === $type ? 'term' : 'post' ) . '_meta';
			if ( ! current_user_can( $cap, (int) $live_id, $key ) ) {
				return $cap . ' refused';
			}
		}
		return true;
	}

	/**
	 * Whether a meta key of a new post may be written before the post exists
	 * (decision 28: the right of a new object can be asked without it only
	 * where it follows the right at the post itself): a core key with an API
	 * of its own, or a key that is not protected and has no authorization
	 * filter of its own (register_meta() with an auth_callback, or a filter
	 * auth_post_meta_<key>). A key registered without an auth_callback
	 * counts as one without (branch 9b): register_meta() then hooks
	 * __return_true for a key that is not protected (wp-includes/meta.php:
	 * 1511-1531), so map_meta_cap gives the right of the post itself
	 * (wp-includes/capabilities.php:425-440), as for the pattern sync status
	 * of a reusable block (wp_create_initial_post_meta()); auth_follows_post().
	 *
	 * @param string $key  Key.
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function new_meta_allowed( $key, $type ) {
		$key = (string) $key;
		if ( in_array( $key, self::CORE_META, true ) ) {
			return true;
		}
		if ( is_protected_meta( $key, 'post' ) ) {
			return false;
		}
		return self::auth_follows_post( "auth_post_meta_{$key}_for_{$type}" ) && self::auth_follows_post( "auth_post_meta_{$key}" );
	}

	/**
	 * Whether an authorization filter of a meta key leaves the right to the
	 * right at the post: none is hooked, or every callback hooked is
	 * __return_true, the one register_meta() hooks for a key registered
	 * without an auth_callback. Any other callback answers at the object
	 * only (fail closed), as does a hook this request does not show as
	 * WordPress keeps it.
	 *
	 * @param string $hook Filter.
	 * @return bool
	 */
	private static function auth_follows_post( $hook ) {
		if ( ! has_filter( (string) $hook ) ) {
			return true;
		}
		$hooks = isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ? $GLOBALS['wp_filter'] : array();
		if ( ! isset( $hooks[ $hook ] ) || ! is_object( $hooks[ $hook ] ) || ! isset( $hooks[ $hook ]->callbacks ) || ! is_array( $hooks[ $hook ]->callbacks ) ) {
			return false;
		}
		foreach ( $hooks[ $hook ]->callbacks as $by_priority ) {
			foreach ( is_array( $by_priority ) ? $by_priority : array() as $one ) {
				if ( ! is_array( $one ) || ! isset( $one['function'] ) || '__return_true' !== $one['function'] ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * The featured image through the core's own API (decision 28):
	 * set_post_thumbnail() to the one value, delete_post_thumbnail() for
	 * none.
	 *
	 * @param int      $live_id Post.
	 * @param string[] $values  The value of the target (the attachment's id here), or none.
	 * @return true|WP_Error
	 */
	private static function write_thumbnail( $live_id, array $values ) {
		if ( array() === $values ) {
			$done = delete_post_thumbnail( (int) $live_id );
			return false === $done && array() !== self::meta_values_now( (int) $live_id, '_thumbnail_id' ) ? self::meta_refused( '_thumbnail_id' ) : true;
		}
		$done = set_post_thumbnail( (int) $live_id, (int) $values[0] );
		return false === $done && self::meta_values_now( (int) $live_id, '_thumbnail_id' ) !== array( (string) (int) $values[0] ) ? self::meta_refused( '_thumbnail_id' ) : true;
	}

	/**
	 * Why WordPress would store another slug than the target's, as a
	 * refusal, or null (branch 9; the steps of wp_insert_post(),
	 * wp-includes/post.php: a pending post written by an account that may
	 * not publish it loses its slug; an empty slug of a post that is not a
	 * draft is made from the title; the slug through sanitize_title(), on an
	 * update in the old sanitizing where the post has it so; a post in the
	 * trash holding the slug would be renamed with __trashed, a row of
	 * another post (wp_add_trashed_suffix_to_post_name_for_trashed_posts());
	 * the slug made unique among the posts of its type and parent, and by
	 * the filters of the core and the plugins, wp_unique_post_slug()).
	 *
	 * @param array       $prepared target_of().
	 * @param int|null    $live_id  The post here, null for a new one.
	 * @param string|null $stored   The slug the post here has now.
	 * @return WP_Error|null
	 */
	public static function slug_refusal( array $prepared, $live_id, $stored ) {
		$slug   = (string) $prepared['postarr']['post_name'];
		$status = (string) $prepared['status'];
		$type   = (string) $prepared['type'];
		$parent = (int) $prepared['postarr']['post_parent'];
		$id     = null === $live_id ? 0 : (int) $live_id;
		$drafts = array( 'draft', 'pending', 'auto-draft' );
		if ( 'pending' === $status && '' !== $slug ) {
			$pto = get_post_type_object( $type );
			$may = null === $live_id ? ( is_object( $pto ) && current_user_can( $pto->cap->publish_posts ) ) : current_user_can( 'publish_post', $id );
			if ( ! $may ) {
				return self::locked( 'post:slug_collision', sprintf( 'WordPress drops the slug %s of a pending post this account may not publish', $slug ) );
			}
		}
		if ( '' === $slug ) {
			return in_array( $status, $drafts, true ) ? null : self::locked( 'post:slug_collision', sprintf( 'the %s has no slug, and WordPress would make one from its title', $status ) );
		}
		$check = sanitize_title( $slug, '', 'old-save' );
		$made  = null !== $live_id && strtolower( urlencode( $slug ) ) === $check && $stored === $check ? $check : sanitize_title( $slug );
		if ( $made !== $slug ) {
			return self::locked( 'post:sanitizer', sprintf( 'field post_name (WordPress would store %s)', $made ) );
		}
		// A post in the trash with the slug: wp_insert_post() renames it
		// (__trashed) before wp_unique_post_slug(), for every post with a
		// slug that does not go to the trash, a draft as well (Codex round 1
		// on branch 9, C: the check was skipped for drafts).
		$trashed = self::trashed_holder( $slug, $id );
		if ( null === $trashed || 0 !== $trashed ) {
			return self::locked( 'post:slug_collision', null === $trashed ? 'the posts of this site could not be read for the slug' : sprintf( 'the post %1$d of this site lies in the trash with the slug %2$s, and WordPress would rename it (__trashed)', $trashed, $slug ) );
		}
		if ( in_array( $status, $drafts, true ) ) {
			// WordPress keeps the slug of a draft as it is (wp_unique_post_slug()).
			return null;
		}
		$unique = wp_unique_post_slug( $slug, $id, $status, $type, $parent );
		if ( (string) $unique !== $slug ) {
			return self::locked( 'post:slug_collision', sprintf( 'WordPress would store the slug %1$s for %2$s', (string) $unique, $slug ) );
		}
		return null;
	}

	/**
	 * A post in the trash with this slug, other than the one given: its id, 0
	 * for none, null where the rows could not be read. Of every type, as
	 * wp_add_trashed_suffix_to_post_name_for_trashed_posts() asks.
	 *
	 * @param string $slug Slug.
	 * @param int    $id   The post written, 0 for a new one.
	 * @return int|null
	 */
	private static function trashed_holder( $slug, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the rows, as the comparison reads them.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'trash' AND ID != %d ORDER BY ID ASC LIMIT 1", (string) $slug, (int) $id ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return null === $found ? 0 : (int) $found;
	}

	/* --------------------------------------- the core types of branch 9b */

	/**
	 * Why a template, a template part or the global styles (THEMED) is not
	 * written for the theme it belongs to, as a refusal, or null (branch 9b;
	 * decision 25: their identity is type, theme and slug). WordPress finds
	 * them by the name of the active theme only: get_block_template() and
	 * render_block_core_template_part() by get_stylesheet() and the term of
	 * wp_theme by its name, WP_Theme_JSON_Resolver::
	 * get_user_data_from_wp_global_styles() likewise, and
	 * wp_filter_wp_template_unique_post_slug() makes the slug of a new
	 * template unique among the templates of the active theme
	 * (wp-includes/theme-templates.php:63). So the theme of the object (its
	 * identity, the slug of its term of wp_theme) has to be the active
	 * stylesheet here, as get_stylesheet() gives it and as the option row
	 * holds it (a filter that shows another theme in this request is no
	 * active theme), the target names that one theme, and an object here
	 * carries it as well. Fail closed, never rewritten into another theme:
	 * where the two sites run other themes, a template of the one is no
	 * template of the other (post:theme_mismatch, with the way).
	 *
	 * @param array      $prepared target_of().
	 * @param array|null $current  current() of the object here; null for a new one.
	 * @return WP_Error|null
	 */
	private static function theme_refusal( array $prepared, $current ) {
		$theme = isset( $prepared['identity']['theme'] ) && is_string( $prepared['identity']['theme'] ) ? $prepared['identity']['theme'] : '';
		$what  = sprintf( 'the %1$s %2$s', (string) $prepared['type'], (string) $prepared['postarr']['post_name'] );
		$other = self::inactive_theme( $theme );
		if ( null !== $other ) {
			return self::locked( 'post:theme_mismatch', $what . ' ' . $other );
		}
		$targets = isset( $prepared['terms']['wp_theme'] ) ? $prepared['terms']['wp_theme'] : array();
		if ( array( $theme ) !== array_values( $targets ) ) {
			return self::locked( 'post:theme_mismatch', sprintf( '%1$s of the staging site names the themes %2$s, not the one theme %3$s', $what, implode( ', ', array_map( 'strval', $targets ) ), $theme ) );
		}
		if ( is_array( $current ) && array( $theme ) !== self::term_keys( $current, 'wp_theme' ) ) {
			return self::locked( 'post:theme_mismatch', sprintf( '%1$s of this site names the themes %2$s, not the one theme %3$s', $what, implode( ', ', self::term_keys( $current, 'wp_theme' ) ), $theme ) );
		}
		return null;
	}

	/**
	 * Why a theme is not the active one here, as the end of a sentence, or
	 * null where it is (branch 9b): the active stylesheet as get_stylesheet()
	 * gives it and as the option row holds it (a filter that shows another
	 * theme in this request is no active theme). The one place theme_refusal()
	 * and the plan (AB_MCP_Sync_Push::verdict()) ask it.
	 *
	 * @param string $theme The theme of an object (the slug of its term of wp_theme).
	 * @return string|null
	 */
	public static function inactive_theme( $theme ) {
		$theme  = (string) $theme;
		$active = (string) get_stylesheet();
		$row    = AB_MCP_Sync_Rows::option( 'stylesheet' );
		$stored = is_array( $row ) ? (string) $row['value'] : null;
		if ( '' === $theme ) {
			return 'of the staging site belongs to no theme (no term of wp_theme)';
		}
		if ( $active !== $theme || $stored !== $theme ) {
			return sprintf( 'belongs to the theme %1$s, and the active theme of this site is %2$s', $theme, $active === $stored ? $active : $active . ' (the option holds ' . ( null === $stored ? 'none' : $stored ) . ')' );
		}
		return null;
	}

	/**
	 * Why a term WordPress reads by its name (NAMED_TAXONOMIES: the theme,
	 * the area of a template part) would not read here as the target names
	 * it, as a refusal, or null (branch 9b): the step writes it by its slug
	 * (wp_set_object_terms() makes a missing term with the slug as its
	 * name); a term of this site with that slug and another name would read
	 * as another theme or area (post:taxonomy_rule). The staging side is
	 * asked in target_of().
	 *
	 * @param array $prepared target_of().
	 * @return WP_Error|null
	 */
	private static function named_refusal( array $prepared ) {
		foreach ( self::NAMED_TAXONOMIES as $taxonomy ) {
			foreach ( isset( $prepared['terms'][ $taxonomy ] ) ? $prepared['terms'][ $taxonomy ] : array() as $slug ) {
				$found = get_term_by( 'slug', (string) $slug, $taxonomy );
				if ( is_object( $found ) && isset( $found->name ) && (string) $found->name !== (string) $slug ) {
					return self::locked( 'post:taxonomy_rule', sprintf( 'the term %1$s of %2$s on this site is named %3$s, and WordPress reads it by its name', (string) $slug, $taxonomy, (string) $found->name ) );
				}
			}
		}
		return null;
	}

	/**
	 * Why the custom CSS of the global styles would not be saved by the site
	 * editor, as a refusal, or null (branch 9b): every value of a key css in
	 * the JSON (the CSS of the styles, of a block, of a variation) through
	 * the rule of WP_REST_Global_Styles_Controller::validate_custom_css()
	 * (css_markup()). The core asks it of the top level only; any other key
	 * css is printed into a STYLE element as well, so it is asked of every
	 * one (fail closed). A value of a key css that is no text is refused as
	 * the core refuses it since 7.1.0. Content that is not empty and does not
	 * decode to an array (no JSON, or deeper than json_decode() reads, or a
	 * scalar) is refused as well, fail closed (Codex on f2af61f..ce9b7bf: it
	 * went unasked): the core shows nothing of it, it raises a notice for a
	 * JSON it cannot decode (WP_Theme_JSON_Resolver::get_user_data(),
	 * wp-includes/class-wp-theme-json-resolver.php:552-575) and ignores
	 * anything else (:581-589), and the site editor saves the styles as JSON
	 * only; the check of the CSS cannot be made, so it refuses with its own
	 * key and the way (the styles saved anew on the staging site). Empty
	 * content holds nothing to ask.
	 *
	 * @param string $content post_content as written.
	 * @return WP_Error|null
	 */
	public static function css_refusal( $content ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return null;
		}
		$data = json_decode( $content, true );
		if ( ! is_array( $data ) ) {
			return self::locked( 'post:css_markup', sprintf( 'the global styles are no JSON object the check of their CSS reads (%s)', JSON_ERROR_NONE === json_last_error() ? 'a value that is no object' : json_last_error_msg() ) );
		}
		return self::css_walk( $data, '', 0 );
	}

	/**
	 * Every value of a key css in decoded JSON, each asked as the walk finds
	 * it (css_refusal()); the path names the place in the refusal only.
	 * GitHub Codex round 1 on free#139, P2: the values were first collected
	 * by their path, and two values whose paths read alike (a key with a dot
	 * in it: blocks.core/button beside blocks → core/button) kept only the
	 * last, so a </style in the first was never asked. Deeper than it is read
	 * is refused (fail closed).
	 *
	 * @param array  $data  Decoded JSON.
	 * @param string $path  Where (dot separated, for the refusal).
	 * @param int    $depth Depth reached.
	 * @return WP_Error|null
	 */
	private static function css_walk( array $data, $path, $depth ) {
		foreach ( $data as $key => $value ) {
			$at = '' === $path ? (string) $key : $path . '.' . $key;
			if ( 'css' === (string) $key ) {
				if ( ! is_string( $value ) ) {
					return self::locked( 'post:css_markup', sprintf( 'the value of %s in the global styles is no text', $at ) );
				}
				if ( self::css_markup( $value ) ) {
					return self::locked( 'post:css_markup', sprintf( 'the CSS of %s in the global styles holds </style or ends in a part of it', $at ) );
				}
				continue;
			}
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( $depth >= AB_MCP_Sync_Canon::MAX_DEPTH ) {
				return self::locked( 'post:css_markup', sprintf( 'the global styles nest deeper than they are read, at %s', $at ) );
			}
			$held = self::css_walk( $value, $at, $depth + 1 );
			if ( null !== $held ) {
				return $held;
			}
		}
		return null;
	}

	/**
	 * Whether a text of CSS holds a closing tag of STYLE or ends in a part
	 * of one: the rule of WP_REST_Global_Styles_Controller::
	 * validate_custom_css() (wp-includes/rest-api/endpoints/
	 * class-wp-rest-global-styles-controller.php:683-760), step for step (a
	 * method of the controller the core keeps protected; the integration
	 * suite holds the two alike): at each <, «</style» in any case, where
	 * the text ends before its eighth character or that character is a
	 * space, a slash or >.
	 *
	 * @param string $css CSS.
	 * @return bool
	 */
	public static function css_markup( $css ) {
		$css    = (string) $css;
		$length = strlen( $css );
		for ( $at = strcspn( $css, '<' ); $at < $length; $at += strcspn( $css, '<', ++$at ) ) {
			$remaining = $length - $at;
			if ( 0 === substr_compare( $css, '</style', $at, min( 7, $remaining ), true ) ) {
				if ( $remaining < 8 ) {
					return true;
				}
				if ( 1 === strspn( $css, " \t\f\r\n/>", $at + 7, 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a navigation moves the one WordPress shows where a navigation
	 * block has no menu of its own, as the reason, or null (branch 9b;
	 * decision 21: a consequence of the core outside the object is allowed
	 * only where it is known whole). Such a block shows the most recently
	 * published navigation of the site (WP_Navigation_Fallback::
	 * get_most_recently_published_navigation(): post_status publish,
	 * orderby date DESC, wp-includes/class-wp-navigation-fallback.php:110-130);
	 * where there is none, WordPress makes one as the page is shown
	 * (get_fallback(), :70-101). A new navigation published after it, a date
	 * moved past it, or a status that makes the object published or not
	 * changes which navigation every such block shows, on pages the plan does
	 * not name: refused where the one shown before and after the write
	 * differ, or where the object would share the latest date with another
	 * (the order of the core is then not told). A navigation that keeps its
	 * place (a changed menu with its date) passes. Read from the rows.
	 *
	 * @param int         $id     The navigation here, 0 for a new one.
	 * @param string|null $before Its post_date where it is published here now, else null.
	 * @param string|null $after  Its post_date where it is published after the write, else null.
	 * @return string|null
	 */
	public static function fallback_moves( $id, $before, $after ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the rows, as the order of the core reads them.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date FROM {$wpdb->posts} WHERE post_type = 'wp_navigation' AND post_status = 'publish' AND ID != %d ORDER BY post_date DESC, ID DESC LIMIT 2", (int) $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return 'the navigations of this site could not be read for the one WordPress shows without a menu of its own';
		}
		$shown = static function ( $date ) use ( $rows ) {
			$top = isset( $rows[0] ) ? $rows[0] : null;
			if ( null !== $date ) {
				if ( null === $top || strcmp( (string) $date, (string) $top['post_date'] ) > 0 ) {
					return 'self';
				}
				if ( (string) $date === (string) $top['post_date'] ) {
					return 'tie';
				}
			}
			if ( null === $top ) {
				return 'none';
			}
			if ( isset( $rows[1] ) && (string) $rows[1]['post_date'] === (string) $top['post_date'] ) {
				return 'others';
			}
			return 'post ' . (int) $top['ID'];
		};
		$was = $shown( $before );
		$is  = $shown( $after );
		if ( 'tie' === $was || 'tie' === $is ) {
			return 'the navigation would share the latest date of the published navigations of this site with another, and which of them WordPress shows in a navigation block without a menu of its own is not told';
		}
		if ( $was !== $is ) {
			$name = static function ( $who ) {
				return 'self' === $who ? 'this navigation' : ( 'none' === $who ? 'none (WordPress would make one as a page is shown)' : ( 'others' === $who ? 'one of two navigations of the same date' : 'the navigation ' . substr( $who, 5 ) ) );
			};
			return sprintf( 'a navigation block without a menu of its own shows %1$s now and would show %2$s after the write', $name( $was ), $name( $is ) );
		}
		return null;
	}

	/**
	 * Why a navigation is refused for the one a navigation block without a
	 * menu of its own shows, or null (branch 9b; decision 21; Codex round 1
	 * of branch 9b, B): only where it moves (fallback_moves()) and such a
	 * block is shown on this site after the run (fallback_shown()). A
	 * navigation every block of the site names by its ref, or that no
	 * template or post shows, moves nothing visible and goes.
	 *
	 * @param int         $id      The navigation here, 0 for a new one.
	 * @param string|null $before  Its post_date where it is published here now, else null.
	 * @param string|null $after   Its post_date where it is published after the write, else null.
	 * @param array       $planned The objects of the run (AB_MCP_Sync_Engine::planned()); none on the way back.
	 * @return string|null
	 */
	public static function fallback_refusal( $id, $before, $after, array $planned ) {
		$moved = self::fallback_moves( $id, $before, $after );
		if ( null === $moved ) {
			return null;
		}
		$shown = self::fallback_shown( $planned );
		return null === $shown ? null : $moved . '; ' . $shown;
	}

	/**
	 * Where this site shows a navigation block without a menu of its own
	 * after the run, as a clause, or null where it shows none (branch 9b):
	 * a core/navigation block with neither ref nor navigationMenuId nor
	 * blocks of its own (render_block_core_navigation(),
	 * wp-includes/blocks/navigation.php:517-547: only then the fallback),
	 * in what the site shows as the run leaves it:
	 * - every template of the active theme (get_block_templates(): the rows
	 *   here over the files of the theme, the templates of plugins), or the
	 *   version the run writes where it writes one;
	 * - the published, private or scheduled posts of a type with a page of
	 *   its own, and the published reusable blocks (a post names one by its
	 *   ref), whose content holds a navigation, a pattern, a reusable block
	 *   or a template part: one bounded query (FALLBACK_POSTS; more is fail
	 *   closed), or the version the run writes;
	 * each with the template parts it names (as the run leaves them, a row,
	 * or a file of the theme) and the patterns it names (the registry of
	 * patterns), to a depth of FALLBACK_DEPTH and FALLBACK_STEPS contents
	 * (deeper or more is fail closed). A block whose ref names a navigation
	 * that is not published shows the fallback as well; it is not counted
	 * (GRENZEN-INHALTE.md).
	 *
	 * @param array $planned The objects of the run: type, op, staging_ref, live_ref, theme, slug, status, content.
	 * @return string|null
	 */
	public static function fallback_shown( array $planned ) {
		global $wpdb;
		$state = array(
			'active' => (string) get_stylesheet(),
			'parts'  => array(),
			'seen'   => array(),
			'steps'  => 0,
		);
		$templates = array();
		$replaced  = array();
		$posts     = array();
		foreach ( $planned as $one ) {
			if ( ! is_array( $one ) || ! isset( $one['type'], $one['content'] ) ) {
				continue;
			}
			$name = (string) ( $one['theme'] ?? '' ) . '//' . (string) ( $one['slug'] ?? '' );
			if ( 'wp_template_part' === $one['type'] ) {
				$state['parts'][ $name ] = (string) $one['content'];
			} elseif ( 'wp_template' === $one['type'] ) {
				$templates[ $name ] = $one;
			} else {
				$posts[] = $one;
			}
			if ( isset( $one['live_ref'] ) && null !== $one['live_ref'] ) {
				$replaced[ (int) $one['live_ref'] ] = true;
			}
		}
		// The templates of the active theme as the run leaves them.
		$found = get_block_templates( array(), 'wp_template' );
		foreach ( is_array( $found ) ? $found : array() as $template ) {
			if ( ! is_object( $template ) || isset( $templates[ (string) $template->theme . '//' . (string) $template->slug ] ) ) {
				continue;
			}
			if ( self::shows_fallback( (string) $template->content, 0, $state ) ) {
				return sprintf( 'the template %s shows a navigation block without a menu of its own', (string) $template->id );
			}
		}
		foreach ( $templates as $name => $one ) {
			if ( $state['active'] === (string) ( $one['theme'] ?? '' ) && self::shows_fallback( (string) $one['content'], 0, $state ) ) {
				return sprintf( 'the template %s the run writes shows a navigation block without a menu of its own', $name );
			}
		}
		// The posts that can show one, as the run leaves them.
		$like = array();
		foreach ( array( 'wp:navigation', 'wp:pattern', 'wp:block ', 'wp:template-part' ) as $mark ) {
			$like[] = '%' . $wpdb->esc_like( $mark ) . '%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the rows, bounded: the posts a navigation block can stand in.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_content FROM {$wpdb->posts} WHERE post_status IN ( 'publish', 'private', 'future' ) AND post_type NOT IN ( 'revision', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'nav_menu_item', 'attachment' ) AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT %d", $like[0], $like[1], $like[2], $like[3], self::FALLBACK_POSTS + 1 ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return 'the posts of this site could not be read for navigation blocks without a menu of their own';
		}
		if ( count( $rows ) > self::FALLBACK_POSTS ) {
			return sprintf( 'more than %d posts of this site hold navigation blocks, patterns, reusable blocks or template parts, more than this check reads', self::FALLBACK_POSTS );
		}
		foreach ( $rows as $row ) {
			if ( isset( $replaced[ (int) $row['ID'] ] ) || ! self::shows_pages( (string) $row['post_type'], (string) $row['post_status'] ) ) {
				continue;
			}
			if ( self::shows_fallback( (string) $row['post_content'], 0, $state ) ) {
				return sprintf( 'the %1$s %2$d shows a navigation block without a menu of its own', (string) $row['post_type'], (int) $row['ID'] );
			}
		}
		foreach ( $posts as $one ) {
			if ( self::shows_pages( (string) $one['type'], (string) ( $one['status'] ?? '' ) ) && self::shows_fallback( (string) $one['content'], 0, $state ) ) {
				return sprintf( 'the %1$s %2$s of the staging site the run writes shows a navigation block without a menu of its own', (string) $one['type'], (string) ( $one['staging_ref'] ?? '' ) );
			}
		}
		return null;
	}

	/**
	 * Whether a post of this type and status is shown: a published, private
	 * or scheduled post of a type with a page of its own, or a published
	 * reusable block (render_block_core_block() shows one only so,
	 * wp-includes/blocks/block.php:27-44).
	 *
	 * @param string $type   Post type.
	 * @param string $status Status.
	 * @return bool
	 */
	private static function shows_pages( $type, $status ) {
		if ( 'wp_block' === $type ) {
			return 'publish' === $status;
		}
		return in_array( $status, array( 'publish', 'private', 'future' ), true ) && is_post_type_viewable( $type );
	}

	/**
	 * Whether a content shows a navigation block without a menu of its own,
	 * itself or through a template part or a pattern it names
	 * (fallback_shown()); fail closed past FALLBACK_DEPTH or FALLBACK_STEPS.
	 *
	 * @param string $content Content.
	 * @param int    $depth   Levels of parts and patterns above.
	 * @param array  $state   active, parts (theme//slug => content the run writes), seen, steps.
	 * @return bool
	 */
	private static function shows_fallback( $content, $depth, array &$state ) {
		++$state['steps'];
		if ( $depth > self::FALLBACK_DEPTH || $state['steps'] > self::FALLBACK_STEPS ) {
			return true;
		}
		$content = (string) $content;
		if ( false === strpos( $content, 'wp:' ) ) {
			return false;
		}
		$parser = new WP_Block_Parser();
		return self::blocks_show_fallback( $parser->parse( $content ), $depth, $state );
	}

	/**
	 * The blocks of shows_fallback(), at any depth of inner blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $depth  Levels of parts and patterns above.
	 * @param array $state  As shows_fallback().
	 * @return bool
	 */
	private static function blocks_show_fallback( array $blocks, $depth, array &$state ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			if ( 'core/navigation' === $name && ! array_key_exists( 'ref', $attrs ) && ! array_key_exists( 'navigationMenuId', $attrs ) && array() === $inner ) {
				return true;
			}
			if ( 'core/template-part' === $name && isset( $attrs['slug'] ) && is_string( $attrs['slug'] ) ) {
				$theme = isset( $attrs['theme'] ) && is_string( $attrs['theme'] ) ? $attrs['theme'] : $state['active'];
				$part  = $theme . '//' . $attrs['slug'];
				if ( $theme === $state['active'] && ! isset( $state['seen'][ 'part:' . $part ] ) ) {
					$state['seen'][ 'part:' . $part ] = true;
					if ( isset( $state['parts'][ $part ] ) ) {
						$text = $state['parts'][ $part ];
					} else {
						$found = get_block_template( $part, 'wp_template_part' );
						$text  = is_object( $found ) && isset( $found->content ) ? (string) $found->content : '';
					}
					if ( self::shows_fallback( $text, $depth + 1, $state ) ) {
						return true;
					}
				}
			}
			if ( 'core/pattern' === $name && isset( $attrs['slug'] ) && is_string( $attrs['slug'] ) && ! isset( $state['seen'][ 'pattern:' . $attrs['slug'] ] ) ) {
				$state['seen'][ 'pattern:' . $attrs['slug'] ] = true;
				$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $attrs['slug'] );
				if ( is_array( $pattern ) && isset( $pattern['content'] ) && self::shows_fallback( (string) $pattern['content'], $depth + 1, $state ) ) {
					return true;
				}
			}
			if ( array() !== $inner && self::blocks_show_fallback( $inner, $depth, $state ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The template parts a content names (core/template-part, at any depth):
	 * their slug and their theme (null where the block names none, then the
	 * active theme, render_block_core_template_part(),
	 * wp-includes/blocks/template-part.php:25). A block without a slug names
	 * no part (it shows none on either site).
	 *
	 * @param string $content Content.
	 * @return array[] slug, theme.
	 */
	public static function part_refs( $content ) {
		$content = (string) $content;
		if ( false === strpos( $content, 'wp:template-part' ) ) {
			return array();
		}
		$parser = new WP_Block_Parser();
		$out    = array();
		$walk   = static function ( array $blocks, $depth ) use ( &$walk, &$out ) {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				if ( 'core/template-part' === ( $block['blockName'] ?? null ) && isset( $block['attrs']['slug'] ) && is_string( $block['attrs']['slug'] ) ) {
					$out[] = array(
						'slug'  => $block['attrs']['slug'],
						'theme' => isset( $block['attrs']['theme'] ) && is_string( $block['attrs']['theme'] ) ? $block['attrs']['theme'] : null,
					);
				}
				if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && $depth < AB_MCP_Sync_Canon::MAX_DEPTH ) {
					$walk( $block['innerBlocks'], $depth + 1 );
				}
			}
		};
		$walk( $parser->parse( $content ), 0 );
		return $out;
	}

	/**
	 * The references of a line the boundary asks by an id or a name, for the
	 * order of the plan (AB_MCP_Sync_Push::order(); branch 9b, Codex round 1,
	 * B): posts, the staging ids a core/block or core/navigation names by
	 * its ref; parts, the template parts it names by theme and slug
	 * (part_refs(); without a theme the active one here), as part:theme//slug;
	 * templates, the template of a page (_wp_page_template), as
	 * template:slug. Read from the content of the line as it is packed.
	 * here: the names of parts and templates this site resolves without the
	 * run (resolves_here(); Codex round 2 of branch 9b, 2): for them an edge
	 * is an order, never a dependency.
	 *
	 * @param array $line The line of the package.
	 * @return array{posts:int[],parts:string[],templates:string[],here:array<string,true>}
	 */
	public static function order_refs( array $line ) {
		$out     = array(
			'posts'     => array(),
			'parts'     => array(),
			'templates' => array(),
			'here'      => array(),
		);
		$content = isset( $line['content']['row']['post_content'] ) ? self::bytes( $line['content']['row']['post_content'] ) : '';
		$refs    = self::part_refs( $content );
		if ( array() !== $refs ) {
			$active = (string) get_stylesheet();
			foreach ( $refs as $ref ) {
				$out['parts'][] = 'part:' . ( null === $ref['theme'] ? $active : (string) $ref['theme'] ) . '//' . (string) $ref['slug'];
			}
		}
		if ( false !== strpos( $content, 'wp:block' ) || false !== strpos( $content, 'wp:navigation' ) ) {
			$parser = new WP_Block_Parser();
			$walk   = static function ( array $blocks, $depth ) use ( &$walk, &$out ) {
				foreach ( $blocks as $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}
					if ( in_array( $block['blockName'] ?? null, array( 'core/block', 'core/navigation' ), true ) && isset( $block['attrs']['ref'] ) && is_int( $block['attrs']['ref'] ) && $block['attrs']['ref'] > 0 ) {
						$out['posts'][] = $block['attrs']['ref'];
					}
					if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && $depth < AB_MCP_Sync_Canon::MAX_DEPTH ) {
						$walk( $block['innerBlocks'], $depth + 1 );
					}
				}
			};
			$walk( $parser->parse( $content ), 0 );
		}
		foreach ( isset( $line['content']['meta'] ) && is_array( $line['content']['meta'] ) ? $line['content']['meta'] : array() as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '_wp_page_template' === self::bytes( $pair[0] ) && ! in_array( self::bytes( $pair[1] ), array( '', 'default' ), true ) ) {
				$out['templates'][] = 'template:' . self::bytes( $pair[1] );
			}
		}
		foreach ( array( 'posts', 'parts', 'templates' ) as $list ) {
			$out[ $list ] = array_values( array_unique( $out[ $list ] ) );
		}
		$type = isset( $line['identity']['post_type'] ) ? (string) $line['identity']['post_type'] : '';
		foreach ( array_merge( $out['parts'], $out['templates'] ) as $name ) {
			if ( self::resolves_here( $name, $type ) ) {
				$out['here'][ $name ] = true;
			}
		}
		return $out;
	}

	/**
	 * Whether this site resolves a part or a template an object names
	 * without the run (Codex round 2 of branch 9b, 2): a template part
	 * (part:theme//slug) of the active theme as a published row or a file
	 * of the theme, as parts_refusal() accepts it; a template
	 * (template:slug) the theme of this site offers for the type of the
	 * object, as precheck() asks it (WP_Theme::get_page_templates(): its
	 * files and custom templates, rows of the site among them).
	 *
	 * @param string $name part:theme//slug or template:slug.
	 * @param string $type The post type of the object that names it.
	 * @return bool
	 */
	public static function resolves_here( $name, $type ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, 'template:' ) ) {
			return self::template_offered( substr( $name, 9 ), (string) $type );
		}
		if ( 0 !== strpos( $name, 'part:' ) || false === strpos( $name, '//' ) ) {
			return false;
		}
		list( $theme, $slug ) = explode( '//', substr( $name, 5 ), 2 );
		return $theme === (string) get_stylesheet() && true === self::part_here( $theme, $slug );
	}

	/**
	 * Whether the theme of this site offers a template for a post type, as
	 * wp_insert_post() checks page_template (WP_Theme::get_page_templates(),
	 * the files and the custom templates of a block theme,
	 * wp-includes/class-wp-theme.php:1327-1398). The one place precheck() and
	 * resolves_here() ask it.
	 *
	 * @param string $template The template (its file, or the slug of a custom template).
	 * @param string $type     Post type.
	 * @return bool
	 */
	private static function template_offered( $template, $type ) {
		$templates = wp_get_theme()->get_page_templates( null, (string) $type );
		return is_array( $templates ) && isset( $templates[ (string) $template ] );
	}

	/**
	 * Whether this site shows a template part of the active theme without a
	 * run: a published row of that theme and slug (part_row(), the query of
	 * render_block_core_template_part(), wp-includes/blocks/template-part.php:29-44)
	 * or a file of the theme (get_block_file_template(), :70-71); null where
	 * the rows could not be read. The one place parts_refusal() and
	 * resolves_here() ask it.
	 *
	 * @param string $theme Theme (the active one).
	 * @param string $slug  Slug.
	 * @return bool|null
	 */
	private static function part_here( $theme, $slug ) {
		$row = self::part_row( (string) $theme, (string) $slug );
		if ( null === $row ) {
			return null;
		}
		if ( $row > 0 ) {
			return true;
		}
		$file = 0 === validate_file( (string) $slug ) ? get_block_file_template( (string) $theme . '//' . (string) $slug, 'wp_template_part' ) : null;
		return is_object( $file ) && isset( $file->content ) && '' !== (string) $file->content;
	}

	/**
	 * The name another object names this one by, for the order of the plan
	 * (order_refs()), or null: a template part as part:theme//slug, a
	 * template as template:slug, from its identity.
	 *
	 * @param array $line The line of the package.
	 * @return string|null
	 */
	public static function order_name( array $line ) {
		$type = isset( $line['identity']['post_type'] ) ? (string) $line['identity']['post_type'] : '';
		$slug = isset( $line['identity']['post_name'] ) && is_string( $line['identity']['post_name'] ) ? $line['identity']['post_name'] : '';
		if ( 'wp_template_part' === $type ) {
			return 'part:' . ( isset( $line['identity']['theme'] ) && is_string( $line['identity']['theme'] ) ? $line['identity']['theme'] : '' ) . '//' . $slug;
		}
		return 'wp_template' === $type ? 'template:' . $slug : null;
	}

	/**
	 * Why a template part a content names would show nothing here, as a
	 * refusal, or null (branch 9b; decision 12: a reference without a
	 * counterpart locks). It names its part by theme and slug, no id, so no
	 * pair maps it; it shows here where its theme is the active one (else
	 * the core shows nothing, render_block_core_template_part(),
	 * wp-includes/blocks/template-part.php:27) and this site has the part:
	 * a published template part of that theme and slug (the query of the
	 * core, :29-44), a part the same run creates before this object
	 * (pending, for the check before the first write), or a file of the
	 * active theme (get_block_file_template(), :70-71). Never rewritten.
	 *
	 * @param array[] $refs    part_refs().
	 * @param array   $pending The objects the run creates by name: wp_template_part => theme//slug => true.
	 * @return WP_Error|null
	 */
	private static function parts_refusal( array $refs, array $pending ) {
		if ( array() === $refs ) {
			return null;
		}
		$active = (string) get_stylesheet();
		foreach ( $refs as $ref ) {
			$theme = null === $ref['theme'] ? $active : (string) $ref['theme'];
			$name  = $theme . '//' . (string) $ref['slug'];
			if ( $theme !== $active ) {
				return self::locked( 'post:reference_unmapped', sprintf( 'the template part %1$s, of a theme that is not the active one here (%2$s), which shows nothing', $name, $active ) );
			}
			if ( isset( $pending['wp_template_part'][ $name ] ) ) {
				continue;
			}
			$here = self::part_here( $theme, (string) $ref['slug'] );
			if ( null === $here ) {
				return self::locked( 'post:reference_unmapped', sprintf( 'the template part %s, whose rows could not be read', $name ) );
			}
			if ( true === $here ) {
				continue;
			}
			return self::locked( 'post:reference_unmapped', sprintf( 'the template part %s, which this site has neither as a template part nor as a file of its theme', $name ) );
		}
		return null;
	}

	/**
	 * A published template part of a theme and slug here, as the core asks
	 * for it (render_block_core_template_part(): post_name, post_status
	 * publish, the term of wp_theme by its name): its id, 0 for none, null
	 * where the rows could not be read.
	 *
	 * @param string $theme Theme.
	 * @param string $slug  Slug.
	 * @return int|null
	 */
	private static function part_row( $theme, $slug ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the rows, as the query of the core reads them.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = r.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = x.term_id WHERE p.post_type = 'wp_template_part' AND p.post_status = 'publish' AND p.post_name = %s AND x.taxonomy = 'wp_theme' AND t.name = %s ORDER BY p.ID ASC LIMIT 1", (string) $slug, (string) $theme ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return null === $found ? 0 : (int) $found;
	}

	/**
	 * Write one part with a call of its own: a meta key (write_meta(), to
	 * exactly the values of the target), the terms of a taxonomy
	 * (write_terms()).
	 *
	 * @param int        $live_id  Post.
	 * @param string     $name     The name of the part (meta:<key>, terms:<taxonomy>).
	 * @param array      $prepared target_of().
	 * @param array|null $pre      current() right before the call.
	 * @return true|WP_Error
	 */
	public static function write_part( $live_id, $name, array $prepared, $pre ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, 'meta:' ) ) {
			$key    = substr( $name, 5 );
			$values = isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array();
			$from   = is_array( $pre ) ? self::meta_values( $pre, $key ) : array();
			if ( '_thumbnail_id' === $key ) {
				// No right of its own at the call (the right of the post, asked
				// ahead): the last read against the state the engine read, then
				// the call; the engine saves the journal between its read and
				// the call (Codex round 4 of branch 11, item 4).
				return AB_MCP_Sync_Write::forward( self::class, $live_id, $pre, static fn() => true, static fn() => self::write_thumbnail( (int) $live_id, $values ) );
			}
			// The right at the object right before the call (decision 28): of
			// a new post the first time it can be asked; a refusal is a call
			// the step does not make, and the object deviates (it stays as a
			// rest). Then the last read against the state the engine read,
			// then the call (AB_MCP_Sync_Write; Codex round 3 of branch 11, A6).
			return AB_MCP_Sync_Write::forward(
				self::class,
				$live_id,
				$pre,
				static function () use ( $live_id, $key, $prepared, $from ) {
					if ( in_array( $key, self::CORE_META, true ) ) {
						return true;
					}
					$may = self::meta_right( (int) $live_id, $key, self::targets( $prepared, $key ), $from );
					return true === $may ? true : new WP_Error( 'ab_mcp_sync_rights', sprintf( 'The meta %1$s of the post was not written: %2$s at the post. Connect with an account that may, or leave the object out of the plan.', $key, $may ) );
				},
				static fn() => self::write_meta( $live_id, $key, $values, $from )
			);
		}
		if ( 0 === strpos( $name, 'terms:' ) ) {
			$taxonomy = substr( $name, 6 );
			$terms    = isset( $prepared['terms'][ $taxonomy ] ) ? $prepared['terms'][ $taxonomy ] : array();
			// The rights of the terms asked ahead (term_right()); the last read
			// against the state the engine read, then the call: the engine
			// saves the journal between its read and the call, through $wpdb
			// and its filter query (Codex round 4 of branch 11, item 4).
			return AB_MCP_Sync_Write::forward( self::class, $live_id, $pre, static fn() => true, static fn() => self::write_terms( $live_id, $taxonomy, $terms ) );
		}
		return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The part %s is of a name this version does not write. Check the object in wp-admin.', $name ) );
	}

	/**
	 * Put one part back from the backup of the step (decision 23): the row
	 * through wp_update_post() (restore_row()), meta raw (restore_meta()),
	 * the terms through wp_set_object_terms() (restore_terms()); each only
	 * where what it holds is the hash decide() named.
	 *
	 * @param string $name    The name of the part.
	 * @param int    $live_id Post.
	 * @param array  $backup  current() of the backup.
	 * @param string $hash    The hash the part has to hold as it is put back.
	 * @param int    $now     Time.
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_part( $name, $live_id, array $backup, $hash, $now ) {
		$name = (string) $name;
		if ( 'row' === $name ) {
			return self::restore_row( $live_id, $backup['row'], self::row_hash( $backup['row'] ), (string) $hash, $now );
		}
		if ( 0 === strpos( $name, 'meta:' ) ) {
			$key = substr( $name, 5 );
			return self::restore_meta( $live_id, $key, self::meta_values( $backup, $key ), (string) $hash );
		}
		return self::restore_terms( $live_id, substr( $name, 6 ), $backup, (string) $hash );
	}

	/**
	 * What the takeover lock of a step covers (AB_MCP_Sync_Guard::begin()):
	 * the post here with its type; nothing for a new one (no id yet).
	 *
	 * @param int|null $live_id Post, null for a new one.
	 * @param string   $subtype Post type.
	 * @return array{posts:array,taxonomies:string[],terms:array}
	 */
	public static function guard_of( $live_id, $subtype ) {
		return array(
			'posts'      => null === $live_id ? array() : array( (int) $live_id => (string) $subtype ),
			'taxonomies' => array(),
			'terms'      => array(),
		);
	}

	/**
	 * The subtype of the object of a journal: its post type.
	 *
	 * @param array $object The object of a journal.
	 * @return string
	 */
	public static function subtype_of( array $object ) {
		return isset( $object['identity']['post_type'] ) ? (string) $object['identity']['post_type'] : 'post';
	}

	/**
	 * @param array $prepared target_of().
	 * @return string The title of the written object (the sample of the check of the run).
	 */
	public static function title_of( array $prepared ) {
		return (string) $prepared['postarr']['post_title'];
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
			$out[ 'terms:' . $taxonomy ] = self::terms_hash( self::term_entries( $current, $taxonomy ) );
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
		return self::terms_hash( self::term_entries( $current, substr( $part, 6 ) ) );
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
	 * The entries of the terms of one taxonomy a post carries, for its hash:
	 * the key of each term (term_keys()) and, where the relationship carries
	 * a term_order other than 0, the order behind «#» (3#2). The canonical
	 * form carries the order (AB_MCP_Sync_Db_Kinds); a relationship with an
	 * order is locked before any mutation (precheck(), post:term_order), and
	 * where one meets the way back all the same (a journal of an earlier
	 * build), the hash of the backup differs from the one wp_set_object_terms()
	 * makes (order 0), so the part is never restored by its keys alone
	 * (GitHub Codex round 5 on free#137, P2). With every order 0 the entries
	 * are the keys, as before.
	 *
	 * @param array  $current  current().
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	public static function term_entries( array $current, $taxonomy ) {
		$slugs = in_array( (string) $taxonomy, self::IDENTITY_TAXONOMIES, true );
		$out   = array();
		foreach ( $current['terms'] as $term ) {
			if ( $term['taxonomy'] !== (string) $taxonomy ) {
				continue;
			}
			$key   = $slugs ? (string) $term['slug'] : (string) (int) $term['term_id'];
			$order = isset( $term['term_order'] ) ? (int) $term['term_order'] : 0;
			$out[] = 0 !== $order ? $key . '#' . $order : $key;
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
		$read = self::live_read( (int) $live_id, $source );
		return is_array( $read ) && isset( $read['hash'] ) && ! empty( $read['complete'] ) ? (string) $read['hash'] : null;
	}

	/**
	 * The canonical form of a post here, read as the inventory reads it.
	 *
	 * @param int   $live_id Post.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return array|null
	 */
	private static function live_read( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( 'post' );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => 'post',
				'ref'    => (string) (int) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) ? $read : null;
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
	/**
	 * A call that saves a post here, with no revision of it deleted (Codex
	 * round 1 of branch 11, A3; nothing is deleted in Z8). Where this site
	 * keeps a number of revisions (WP_POST_REVISIONS, the filter
	 * wp_revisions_to_keep), wp_save_post_revision() deletes the oldest
	 * beyond it as the post is saved; for this call and this post the number
	 * is none (-1, keep them all). A site that keeps no revisions (0) makes
	 * none, as before; a post of a type without revisions makes none. The
	 * core asks the filter of the type of the post after it
	 * (wp_{$post_type}_revisions_to_keep, wp-includes/revision.php; for the
	 * CSS wp_custom_css_revisions_to_keep): the same answer there, as the
	 * first filter meets the post (Codex round 2 of branch 11, A7). Both
	 * run last (PHP_INT_MAX) and go again in any case (finally). Every
	 * writer of the engine saves an existing post through it, forward and
	 * back.
	 *
	 * @param int      $post_id The post.
	 * @param callable $call    The call that saves it.
	 * @return mixed What the call answers.
	 */
	public static function keeping_revisions( $post_id, callable $call ) {
		$keep  = static function ( $num, $post ) use ( $post_id ) {
			return is_object( $post ) && (int) $post->ID === (int) $post_id && (int) $num > 0 ? -1 : $num;
		};
		$types = array();
		$first = static function ( $num, $post ) use ( $post_id, $keep, &$types ) {
			if ( is_object( $post ) && (int) $post->ID === (int) $post_id && isset( $post->post_type ) ) {
				$hook = 'wp_' . (string) $post->post_type . '_revisions_to_keep';
				if ( ! isset( $types[ $hook ] ) ) {
					$types[ $hook ] = true;
					add_filter( $hook, $keep, PHP_INT_MAX, 2 );
				}
			}
			return $keep( $num, $post );
		};
		add_filter( 'wp_revisions_to_keep', $first, PHP_INT_MAX, 2 );
		try {
			return $call();
		} finally {
			remove_filter( 'wp_revisions_to_keep', $first, PHP_INT_MAX );
			foreach ( array_keys( $types ) as $hook ) {
				remove_filter( $hook, $keep, PHP_INT_MAX );
			}
		}
	}

	public static function write_row( array $prepared, $live_id ) {
		$postarr = $prepared['postarr'];
		if ( null !== $live_id ) {
			$postarr['ID'] = (int) $live_id;
			// No revision of the post deleted by the save (nothing is deleted
			// in Z8): keeping_revisions().
			$id = self::keeping_revisions(
				(int) $live_id,
				static function () use ( $postarr ) {
					return wp_update_post( wp_slash( $postarr ), true );
				}
			);
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
	 * @param string   $type    The meta type: post, or term (AB_MCP_Sync_Term_Writer).
	 * @return true|WP_Error
	 */
	public static function write_meta( $live_id, $key, array $values, array $current, $type = 'post' ) {
		$id   = (int) $live_id;
		$type = (string) $type;
		// The operations of meta_ops(), whose rights the step asked: no other.
		$ops = self::meta_ops( $values, $current );
		if ( array( 'edit' ) === $ops ) {
			$done = update_metadata( $type, $id, wp_slash( (string) $key ), wp_slash( $values[0] ) );
			// false also where the value is the same already.
			return false === $done && self::meta_values_now( $id, $key, $type ) !== array( self::stored_form( $values[0] ) ) ? self::meta_refused( $key ) : true;
		}
		if ( in_array( 'delete', $ops, true ) && false === delete_metadata( $type, $id, wp_slash( (string) $key ) ) ) {
			return self::meta_refused( $key );
		}
		if ( in_array( 'add', $ops, true ) ) {
			foreach ( $values as $value ) {
				if ( false === add_metadata( $type, $id, wp_slash( (string) $key ), wp_slash( $value ) ) ) {
					return self::meta_refused( $key );
				}
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
	 * caches of the post and its terms. A type any page may show (SITE_WIDE,
	 * branch 9b: a template, a template part, the global styles, a
	 * navigation, a reusable block) empties the page cache of the whole site,
	 * not of the object, which has no page of its own; the global styles
	 * empty the caches of theme.json as well (wp_clean_theme_json_cache(),
	 * wp-includes/global-styles-and-settings.php:430-438: the group
	 * theme_json, kept within a request only, wp-includes/load.php:928, and
	 * the data of WP_Theme_JSON_Resolver). WordPress 7.1.3 keeps no
	 * transient of the global styles (none named _wp_theme_json or alike);
	 * the queries of templates and navigations follow the last change of the
	 * posts, which clean_post_cache() sets.
	 *
	 * @param int $live_id Post.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		$type = AB_MCP_Sync_Rows::post_type_of( (int) $live_id );
		clean_post_cache( (int) $live_id );
		wp_cache_delete( (int) $live_id, 'post_meta' );
		foreach ( self::TAXONOMIES as $taxonomy ) {
			wp_cache_delete( (int) $live_id, $taxonomy . '_relationships' );
		}
		$done = array( 'post', 'post_meta', 'term_relationships' );
		if ( class_exists( 'AB_MCP_Builder_Caches' ) ) {
			$done = array_merge( $done, (array) AB_MCP_Builder_Caches::clear( (int) $live_id ) );
		}
		if ( 'wp_global_styles' === $type && function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
			$done[] = 'theme_json';
		}
		if ( class_exists( 'AB_MCP_Page_Cache' ) ) {
			// A type of the whole site, or one that cannot be read: every page.
			$whole  = ! is_string( $type ) || in_array( $type, self::SITE_WIDE, true );
			$purged = $whole ? AB_MCP_Page_Cache::purge_site() : AB_MCP_Page_Cache::purge_posts( array( (int) $live_id ) );
			if ( is_array( $purged ) ) {
				$done[] = $whole ? 'page_cache_site' : 'page_cache';
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
		// Putting a navigation back where that moves the one WordPress shows
		// in a navigation block without a menu of its own, where a template or
		// a post of this site shows such a block (decision 21, as forward;
		// branch 9b): another navigation published since, or the row back to
		// another date or status, would change every such block.
		if ( 'wp_navigation' === $backup['post_type'] ) {
			$moved = self::fallback_refusal(
				(int) $live_id,
				'publish' === $current['row']['post_status'] ? (string) $current['row']['post_date'] : null,
				'publish' === $backup['post_status'] ? (string) $backup['post_date'] : null,
				array()
			);
			if ( null !== $moved ) {
				return array(
					'state' => 'check_needed',
					'note'  => sprintf( 'Check needed: putting the navigation back would change which navigation WordPress shows where a navigation block has no menu of its own (%s). Set the navigation by hand in the site editor.', $moved ),
				);
			}
		}
		// The custom CSS of the global styles the row would put back keeps the
		// rule of the site editor, as forward (css_refusal(); branch 9b, Codex
		// round 1, A: the backup is written through wp_update_post(), past the
		// check of the REST controller, and an account with unfiltered_html
		// meets no kses). Refused, the row is left with its value before, as
		// a part whose right the way back refuses.
		if ( 'wp_global_styles' === $backup['post_type'] ) {
			$held = self::css_refusal( (string) $backup['post_content'] );
			if ( null !== $held ) {
				$data = $held->get_error_data();
				return array(
					'state' => 'left',
					'note'  => sprintf( 'Not put back: the backed-up global styles hold custom CSS the site editor refuses to save (post:css_markup: %s), so the row is left as it is; its value before stands beside it (value_before). Set the styles by hand in the site editor.', is_array( $data ) && isset( $data['lock']['at'] ) ? (string) $data['lock']['at'] : 'the CSS' ),
				);
			}
		}
		// The theme is not asked on the way back, unlike forward
		// (theme_refusal()): the row goes back to the same post, whose term of
		// wp_theme the step did not change (the pair is of one identity, type,
		// theme and slug, and the part terms:wp_theme goes back by its own
		// rule); what the forward check keeps (that the written object shows
		// under the active theme, and that a new template is made unique among
		// the templates of the active theme) has no counterpart for a post
		// that is there: wp_unique_post_slug() asks a post with an id the term
		// it carries (wp_filter_wp_template_unique_post_slug(),
		// wp-includes/theme-templates.php:64-67). A theme switched since the
		// run gets its rows back as they were.
		$postarr = array_intersect_key( $backup, array_flip( self::WRITTEN_COLUMNS ) );
		$postarr['ID']          = (int) $live_id;
		$postarr['post_author'] = (int) $postarr['post_author'];
		$postarr['post_parent'] = (int) $postarr['post_parent'];
		$postarr['menu_order']  = (int) $postarr['menu_order'];
		$postarr['edit_date']   = true;
		// The last read, after every check above that may run a hook (the
		// menus, the navigation, the styles), against what the step wrote,
		// then the call (AB_MCP_Sync_Write; Codex rounds 2 and 3 of branch
		// 11, A6). The rights were asked right before (restore_right()).
		$id = AB_MCP_Sync_Write::back(
			static fn() => true,
			static fn() => self::current( (int) $live_id ),
			static fn( $again ) => is_array( $again ) && hash_equals( $written, self::row_hash( $again['row'] ) ),
			static function () use ( $live_id, $postarr ) {
				return self::keeping_revisions(
					(int) $live_id,
					static function () use ( $postarr ) {
						return wp_update_post( wp_slash( $postarr ), true );
					}
				);
			},
			'The row changed as it was put back (a hook of this site), so it is left as it is.'
		);
		if ( is_wp_error( $id ) || is_array( $id ) ) {
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
	 * @param string   $type    The meta type: post, or term (AB_MCP_Sync_Term_Writer).
	 * @return array{state:string,note?:string}|WP_Error state restored, unchanged or left.
	 */
	public static function restore_meta( $live_id, $key, array $backup, $written, $type = 'post' ) {
		$id    = (int) $live_id;
		$type  = 'term' === $type ? 'term' : 'post';
		$now   = self::meta_values_now( $id, $key, $type );
		$table = 'term' === $type ? 'termmeta' : 'postmeta';
		$col   = 'term' === $type ? 'term_id' : 'post_id';
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
		$rows = AB_MCP_Sync_Rows::meta( $type, $id );
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
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->$table} SET meta_value = %s WHERE meta_id = %d AND {$col} = %d AND CAST( meta_value AS BINARY ) = CAST( %s AS BINARY ) AND OCTET_LENGTH( meta_value ) = %d", $backup[ $i ], (int) $own[ $i ]['meta_id'], $id, $own[ $i ]['value'], strlen( $own[ $i ]['value'] ) ) );
			if ( 1 !== (int) $done ) {
				wp_cache_delete( $id, $type . '_meta' );
				return array(
					'state' => 'left',
					'note'  => sprintf( 'A row of the meta %s changed while it was put back, so the rest is left as it is.', $key ),
				);
			}
		}
		for ( $i = $count; $i < count( $own ); $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- only with the bytes the step wrote.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->$table} WHERE meta_id = %d AND {$col} = %d AND CAST( meta_value AS BINARY ) = CAST( %s AS BINARY ) AND OCTET_LENGTH( meta_value ) = %d", (int) $own[ $i ]['meta_id'], $id, $own[ $i ]['value'], strlen( $own[ $i ]['value'] ) ) );
		}
		for ( $i = $count; $i < count( $backup ); $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the raw row the step removed, as it was.
			$wpdb->insert( $wpdb->$table, array( $col => $id, 'meta_key' => (string) $key, 'meta_value' => $backup[ $i ] ), array( '%d', '%s', '%s' ) );
		}
		wp_cache_delete( $id, $type . '_meta' );
		$after = self::meta_values_now( $id, $key, $type );
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
	 * The order of the relationships (term_order) is part of what has to read
	 * back as the backup (term_entries()): wp_set_object_terms() makes every
	 * relationship anew with order 0, so a backed-up order other than 0 never
	 * reads as restored (unclear, with the reason; GitHub Codex round 5 on
	 * free#137, P2). Such an object is locked before the step writes
	 * (precheck(), post:term_order); this is the second check.
	 *
	 * @param int    $live_id  Post.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $backup   current() of the backup.
	 * @param string $written  The hash the term entries have to hold now: what the step, or the row put back, wrote (the journal).
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
		$before = self::terms_hash( self::term_entries( $backup, $taxonomy ) );
		$now    = self::terms_hash( self::term_entries( $current, $taxonomy ) );
		if ( $now === $before ) {
			return array( 'state' => 'unchanged' );
		}
		if ( ! hash_equals( (string) $written, $now ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The terms of %s were changed since the step wrote them, so they are left as they are.', $taxonomy ),
			);
		}
		$done = wp_set_object_terms( (int) $live_id, self::target_term_keys( $taxonomy, self::term_keys( $backup, $taxonomy ) ), (string) $taxonomy, false );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$after = self::current( (int) $live_id );
		if ( ! is_array( $after ) || self::terms_hash( self::term_entries( $after, $taxonomy ) ) !== $before ) {
			$orders = is_array( $after ) && self::terms_hash( self::term_keys( $after, $taxonomy ) ) === self::terms_hash( self::term_keys( $backup, $taxonomy ) );
			return array(
				'state' => 'unclear',
				'note'  => $orders
					? sprintf( 'The terms of %s are back, but not their order (term_order): wp_set_object_terms() makes every relationship anew with the order 0. Set the order by hand (the plugin that sorts the terms).', $taxonomy )
					: sprintf( 'The terms of %s were written back but do not read as the backup.', $taxonomy ),
			);
		}
		return array( 'state' => 'restored' );
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
	public static function meta_before( $key, array $values ) {
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
	public static function shown_value( $key, $value ) {
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
	public static function cut( $text, $max ) {
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
	 * The value of a meta row as the metadata API stores it back to the
	 * same bytes: a serialized array unpacked (update_metadata() serializes
	 * it again, the same way), a text with its addresses rewritten; a
	 * serialized value that is no array (a scalar, an object) is refused:
	 * maybe_serialize() would serialize it once more.
	 *
	 * @param string $raw     As stored on the staging site.
	 * @param array  $plan    AB_MCP_Sync_Refs::plan(), collecting missing.
	 * @param bool   $changed Set when the addresses changed.
	 * @param string $lock    The lock of a value the API cannot store as it is (post:meta_rule, term:meta_rule).
	 * @return string|array|WP_Error
	 */
	public static function meta_value( $raw, array &$plan, &$changed, $lock = 'post:meta_rule' ) {
		if ( is_serialized( $raw ) ) {
			$read = AB_MCP_Sync_Canon::unserialized( $raw );
			if ( ! $read['ok'] || ! is_array( $read['value'] ) ) {
				return self::locked( $lock, 'a serialized value that is no array is not written through the metadata API as it is' );
			}
			$text = AB_MCP_Sync_Refs::rewrite_value( $raw, $plan, $changed );
			$read = AB_MCP_Sync_Canon::unserialized( $text );
			if ( ! $read['ok'] || ! is_array( $read['value'] ) ) {
				return self::locked( $lock, 'a serialized value that does not unpack after its addresses were rewritten' );
			}
			// The metadata API stores serialize() of what it is handed: a
			// value that does not serialize back to its own bytes (a float
			// written by an older PHP, a key or a string written by hand) would
			// read back other than its target. Locked before anything is
			// written (decision 17, the sanitizers ahead; branch 9).
			if ( serialize( $read['value'] ) !== $text ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as maybe_serialize() stores an array.
				return self::locked( $lock, 'a serialized value whose bytes the metadata API would not store again as they are (serialize() of it differs)' );
			}
			return $read['value'];
		}
		return AB_MCP_Sync_Refs::rewrite_value( $raw, $plan, $changed );
	}

	/**
	 * The bytes the metadata API stores for a value handed to it.
	 *
	 * @param string|array $value Value.
	 * @return string
	 */
	public static function stored_form( $value ) {
		return is_array( $value ) ? serialize( $value ) : (string) $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as maybe_serialize() stores an array.
	}

	/**
	 * The bytes WordPress stores for what sanitize_meta() gave back
	 * (update_metadata(), add_metadata(): maybe_serialize(), then $wpdb
	 * writes a scalar as its text: a bool as '1' or '', a number as PHP
	 * writes it); null where it stores no such bytes (NULL, or a value no
	 * column holds), which no target equals (fail closed).
	 *
	 * @param mixed $value What sanitize_meta() gave back.
	 * @return string|null
	 */
	public static function db_form( $value ) {
		$value = maybe_serialize( $value );
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * @param int    $id   Post (or term).
	 * @param string $key  Key.
	 * @param string $type The meta type: post or term.
	 * @return string[]|null The values now, by row.
	 */
	public static function meta_values_now( $id, $key, $type = 'post' ) {
		$rows = AB_MCP_Sync_Rows::meta( (string) $type, (int) $id );
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
	public static function login_of( array $line, $at ) {
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
	public static function user_by_login( $login, array $ctx ) {
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
	public static function unresolved( array $missing, array $pending ) {
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
	public static function missing_text( array $missing ) {
		$out = array();
		foreach ( array_slice( $missing, 0, 5 ) as $m ) {
			$out[] = (string) ( $m['space'] ?? '?' ) . ' ' . (int) ( $m['id'] ?? 0 );
		}
		return 'references without a counterpart on this site: ' . implode( ', ', $out );
	}

	/**
	 * The first lock the line of a package carries, as a refusal, or null
	 * (branch 9): what the canonical form found in the staging object (a
	 * page builder that is not released, decision 31; a type the table
	 * locks, such as a changeset, decision 30; a value it cannot read, a
	 * credential in a field). The report holds such an object back already;
	 * the writer refuses it as well, before anything is written, so no path
	 * past the report writes it.
	 *
	 * @param array $line The line of the package.
	 * @return WP_Error|null
	 */
	public static function line_lock( array $line ) {
		$locks = isset( $line['locks'] ) && is_array( $line['locks'] ) ? $line['locks'] : array();
		foreach ( $locks as $lock ) {
			if ( is_array( $lock ) && isset( $lock['key'], $lock['stage'], $lock['reason'] ) && is_string( $lock['key'] ) ) {
				return self::refusal(
					array(
						'key'    => $lock['key'],
						'stage'  => (string) $lock['stage'],
						'reason' => (string) $lock['reason'],
					),
					sprintf( 'the object of the staging site, at %s', isset( $lock['at'] ) && is_string( $lock['at'] ) ? $lock['at'] : 'its form' )
				);
			}
		}
		return array() === $locks ? null : self::locked( 'post:lock_unread', 'the object of the staging site carries a lock this version does not read' );
	}

	/**
	 * The locks of the canonical form of an object of this site, as a
	 * refusal, or null (the mirror of line_lock() for the live side): what
	 * the report holds back on this side, checked under the lock of the
	 * boundary as well.
	 *
	 * @param array|null $read The result of AB_MCP_Sync_Kind::canonical().
	 * @param string     $what The object, for the detail (the post of this site).
	 * @return WP_Error|null
	 */
	public static function read_lock( $read, $what ) {
		if ( ! is_array( $read ) || empty( $read['locks'] ) || ! is_array( $read['locks'] ) ) {
			return null;
		}
		foreach ( $read['locks'] as $lock ) {
			if ( is_array( $lock ) && isset( $lock['key'], $lock['stage'], $lock['reason'] ) && is_string( $lock['key'] ) ) {
				return self::refusal(
					array(
						'key'    => $lock['key'],
						'stage'  => (string) $lock['stage'],
						'reason' => (string) $lock['reason'],
					),
					sprintf( '%1$s, at %2$s', (string) $what, isset( $lock['at'] ) && is_string( $lock['at'] ) ? $lock['at'] : 'its form' )
				);
			}
		}
		return self::locked( 'post:lock_unread', sprintf( '%s carries a lock this version does not read', (string) $what ) );
	}

	/**
	 * A refusal with a lock as the table or the canonical form names it.
	 *
	 * @param array  $lock   key, stage, reason.
	 * @param string $detail What was found.
	 * @return WP_Error
	 */
	private static function refusal( array $lock, $detail ) {
		return new WP_Error(
			'ab_mcp_sync_locked',
			sprintf( 'Not written (%1$s, from %2$s): %3$s %4$s Use the editor in wp-admin to take this object over by hand, or leave it out of the plan.', $lock['key'], $lock['stage'], $lock['reason'], ucfirst( (string) $detail ) . '.' ),
			array( 'lock' => $lock + array( 'at' => (string) $detail ) )
		);
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
		return self::refusal( $lock, $detail );
	}

	/**
	 * @param string $what What the account may not do.
	 * @return WP_Error
	 */
	public static function rights( $what ) {
		return new WP_Error( 'ab_mcp_sync_rights', sprintf( 'Not written: this account may not %s on this site. Connect with an account that may, or leave the object out of the plan.', $what ), array( 'needs' => $what ) );
	}

	/**
	 * @param string $key Key.
	 * @return WP_Error
	 */
	public static function meta_refused( $key ) {
		return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The meta %s was not written: a filter of this site refused it, or the database did. Nothing more of the step is written; ask the site administrator which plugin holds it.', $key ) );
	}
}
