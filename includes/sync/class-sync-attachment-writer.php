<?php
/**
 * The write side of the kind attachment (contract A of the plan of
 * 01.11.2026; Z8 branch 10, decisions 16, 17, 23, 26 and 28): what a step of
 * a run writes for one attachment and its files, how it is backed up, read
 * back and put back. The engine picks it by the kind
 * (AB_MCP_Sync_Engine::WRITERS), with the same methods as the writers of the
 * kinds post and term.
 *
 * AN ATTACHMENT IS A POST WITH FILES. Its row, its meta and its terms go
 * the ways of the kind post (AB_MCP_Sync_Post_Writer: the row through
 * wp_insert_attachment() and wp_update_post() with both date columns and
 * edit_date, meta through the metadata API, terms through
 * wp_set_object_terms(); the journal, the backup, the read back and the
 * way back by part, byte for byte conditional, as there). What is its own:
 *
 *  - THE FILES (decision 26). The target names its file list (the files of
 *    the staging attachment, each by its path relative to the uploads, its
 *    size and its SHA-256, AB_MCP_Sync_Media). For each: where this site has
 *    the same file at the path byte for byte, nothing is written (present);
 *    where it has nothing, the step lays it exclusively (fopen 'xb'), as
 *    the run's own; where it has another file, a link or anything else, the
 *    attachment is locked (attachment:replace_file, attachment:unsafe_path,
 *    Z8b): nothing is ever written over, and nothing deleted, not even a
 *    file of the run's own. A path another attachment of this site names is
 *    locked (attachment:shared_path): a deletion of the one would take the
 *    other's file. The files come from the packages of media of the run
 *    (AB_MCP_Sync_Media_Package); every file is first provided whole in a
 *    temporary file of this request, checked against its size and SHA-256
 *    and by its content (wp_check_filetype_and_ext() with the types this
 *    account may upload, as the core checks an upload;
 *    attachment:file_type), then laid one after the other, the journal saved
 *    before each. Where one fails, the files laid before it stay as the
 *    run's own (named with their paths), and the row is not written: the
 *    attachment is not made. The sizes are not made anew: the files are
 *    those the staging site made.
 *  - THE OWN META (decision 28: «die Schlüssel der Medien-Art in ihrer
 *    Art»): _wp_attached_file, _wp_attachment_metadata,
 *    _wp_attachment_backup_sizes and _wp_attachment_image_alt are protected
 *    keys map_meta_cap refuses to anyone; WordPress writes them with the
 *    right to the attachment (edit_post; the alternative text so in the
 *    media library and the REST API), and the three that name files as an
 *    upload does (upload_files). So does this writer, forward and on the way
 *    back (restore_right()), through the metadata API: an optimizer that
 *    hooks wp_update_attachment_metadata does not run on a takeover, and
 *    where a hook of this site changes a file or a value all the same, the
 *    object deviates (its hash holds every file of its list). The file of a
 *    new attachment goes with its row (wp_insert_attachment() with the file,
 *    as an upload makes it). Any other key follows the rule of the kind
 *    post (map_meta_cap at the attachment; a new attachment only with keys
 *    whose right follows the attachment).
 *  - THE PARENT (decision 16: a weak reference). The post an attachment
 *    hangs under is written by a step of its own after that post where the
 *    same run creates it (the parent step of the plan): the attachment is
 *    made unattached first, its pair takes the staging base of that state
 *    (so a report sees it as changed on staging until the parent step went
 *    through), and the parent step writes the whole target. A parent
 *    without a pair that the run does not create locks the attachment
 *    (post:reference_unmapped).
 *  - THE STATUSES: inherit (the status of an upload) and private.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-post-writer.php';
require_once __DIR__ . '/class-sync-media.php';
require_once __DIR__ . '/class-sync-media-package.php';
require_once __DIR__ . '/class-sync-run.php';

/**
 * Class AB_MCP_Sync_Attachment_Writer
 */
final class AB_MCP_Sync_Attachment_Writer {

	/** The post type. */
	const TYPE = 'attachment';

	/** The statuses a target may have: an upload's, and private. */
	const STATUSES = array( 'inherit', 'private' );

	/**
	 * The meta keys of the kind attachment with a rule of their own (see the
	 * class): written through the metadata API with the right to the
	 * attachment, never through map_meta_cap.
	 */
	const OWN_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_wp_attachment_image_alt' );

	/** Of OWN_META, the keys that name the files of the list: a change of them needs upload_files as well. */
	const FILE_META = AB_MCP_Sync_Media::META_KEYS;

	/** The taxonomies of terms on an attachment this version writes (a site that registers them for attachments). */
	const TAXONOMIES = array( 'category', 'post_tag' );

	/** The rules a value before is composed by, as the kind post. */
	const VALUE_RULES = AB_MCP_Sync_Post_Writer::VALUE_RULES;

	/** Bytes of the files one step lays at most: those of one package of media. */
	const MAX_BYTES = AB_MCP_Sync_Media_Package::MAX_BYTES;

	/** Attachments a check of shared paths reads at most beside the one written (named_by()); more is fail closed. */
	const SHARED_MAX = 20000;

	/** Called right before a file is opened to be laid: callable( string $full ): void. Set by tests only (another hand between the check and the open). */
	public static $probe = null;

	/* ------------------------------------------------------------ prepare */

	/**
	 * What a step writes for one attachment, from its line of the package of
	 * objects and the item of the plan (see AB_MCP_Sync_Post_Writer::
	 * target_of() for the fields): the row, the meta by key, the terms, the
	 * file list (files: path, sha256, size) and the media of the run that
	 * carry it (media), the target and its hash.
	 *
	 * @param array $line The line of the package (content, form, identity, refs).
	 * @param array $item The item of the plan (op, live_ref, parent_later).
	 * @param array $ctx  As for the kind post; media: the files of the packages of media of the run (AB_MCP_Sync_Engine::lines()).
	 * @return array|WP_Error
	 */
	public static function target_of( array $line, array $item, array $ctx ) {
		$type = isset( $line['identity']['post_type'] ) ? (string) $line['identity']['post_type'] : '';
		if ( self::TYPE !== $type ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:other_kind', sprintf( 'post type %s in a step of the kind attachment', $type ) );
		}
		$held = AB_MCP_Sync_Post_Writer::line_lock( $line );
		if ( null !== $held ) {
			return $held;
		}
		if ( ! isset( $line['content']['row'], $line['content']['meta'], $line['content']['terms'], $line['form'], $line['form']['files'] ) || ! is_array( $line['form'] ) || ! is_array( $line['form']['files'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', 'the package carries no content or no file list of the attachment (a row of it could not be read on the staging site)' );
		}
		$row = array();
		foreach ( $line['content']['row'] as $column => $value ) {
			$row[ (string) $column ] = AB_MCP_Sync_Post_Writer::bytes( $value );
		}
		foreach ( AB_MCP_Sync_Post_Writer::WRITTEN_COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', sprintf( 'the package names no column %s', $column ) );
			}
		}
		if ( '' !== $row['post_password'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:password_protected', 'post_password' );
		}
		$status = $row['post_status'];
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$key = 'trash' === $status ? 'post:in_trash' : ( 'auto-draft' === $status ? 'post:auto_draft' : 'post:status_rule' );
			return AB_MCP_Sync_Post_Writer::locked( $key, sprintf( 'attachment status %s', '' === $status ? '(none)' : $status ) );
		}
		$create  = 'create' === ( $item['op'] ?? '' );
		$plan    = AB_MCP_Sync_Refs::plan( $ctx['map'], $ctx['addresses'], isset( $ctx['routes'] ) ? $ctx['routes'] : null );
		$changed = false;
		$pending = isset( $ctx['pending'] ) && is_array( $ctx['pending'] ) ? $ctx['pending'] : array();

		// The parent, a weak reference (decision 16): by its pair; one the run
		// creates is set by the parent step after it (parent_later: the
		// attachment is made unattached first).
		$parent = (int) $row['post_parent'];
		$later  = false;
		if ( $parent > 0 ) {
			$mapped = AB_MCP_Sync_Refs::mapped_id( $plan, 'post', $parent );
			$plan['missing'] = array();
			if ( $create && ! empty( $item['parent_later'] ) ) {
				$later  = true;
				$mapped = 0;
			} elseif ( null === $mapped && isset( $pending['post'][ $parent ] ) ) {
				// The check before the first write: the run creates it.
				$later  = true;
				$mapped = 0;
			}
			if ( null === $mapped ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:reference_unmapped', sprintf( 'the post %d of the staging site the attachment hangs under (post_parent): pair it, or take it over in the same run', $parent ) );
			}
			$parent = (int) $mapped;
		}
		$author = 0;
		if ( (int) $row['post_author'] > 0 ) {
			$login = AB_MCP_Sync_Post_Writer::login_of( $line, 'post_author' );
			$user  = null === $login ? null : AB_MCP_Sync_Post_Writer::user_by_login( $login, $ctx );
			if ( null === $user ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:author_unknown', null === $login ? 'post_author' : sprintf( 'login %s', $login ) );
			}
			$author = (int) $user;
		}
		$content = AB_MCP_Sync_Refs::rewrite_content( $row['post_content'], $plan );
		$missing = AB_MCP_Sync_Post_Writer::unresolved( $content['missing'], $pending );
		if ( array() !== $missing ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:reference_unmapped', AB_MCP_Sync_Post_Writer::missing_text( $missing ) );
		}
		$texts = array();
		foreach ( array( 'post_title', 'post_excerpt', 'post_content_filtered' ) as $column ) {
			$texts[ $column ] = AB_MCP_Sync_Refs::rewrite_text( $row[ $column ], $plan, array(), $changed );
			$missing          = AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], $pending );
			if ( array() !== $missing ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:reference_unmapped', AB_MCP_Sync_Post_Writer::missing_text( $missing ) );
			}
		}
		// The meta: the own keys with their rule, the poster of an audio or a
		// video by its pair, any other by the rule of the kind post.
		$meta  = array();
		$extra = AB_MCP_Redactor::extra_keys();
		foreach ( $line['content']['meta'] as $pair ) {
			$key   = AB_MCP_Sync_Post_Writer::bytes( $pair[0] );
			$value = AB_MCP_Sync_Post_Writer::bytes( $pair[1] );
			if ( '' === $key || AB_MCP_Sync_Exclusions::post_meta( $key ) || AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {
				continue;
			}
			if ( $create && ! in_array( $key, self::OWN_META, true ) && ! AB_MCP_Sync_Post_Writer::new_meta_allowed( $key, self::TYPE ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:create_with_object_meta', sprintf( 'meta key %s of the attachment (protected, or a plugin answers its right at the object only)', $key ) );
			}
			if ( in_array( $key, self::OWN_META, true ) && isset( $meta[ $key ] ) ) {
				// WordPress reads one value of each (get_post_meta() single).
				return AB_MCP_Sync_Post_Writer::locked( 'post:meta_rule', sprintf( 'the meta key %s of the attachment has more than one row', $key ) );
			}
			if ( '_thumbnail_id' === $key ) {
				$mapped          = 1 === preg_match( '/^[1-9][0-9]*\z/', $value ) ? AB_MCP_Sync_Refs::mapped_id( $plan, 'post', (int) $value ) : null;
				$plan['missing'] = array();
				if ( null === $mapped && isset( $pending['post'][ (int) $value ] ) ) {
					$mapped = 0;
				}
				if ( null === $mapped || isset( $meta[ $key ] ) ) {
					return AB_MCP_Sync_Post_Writer::locked( 'post:reference_unmapped', sprintf( 'the image %s of the staging site the attachment shows (_thumbnail_id)', $value ) );
				}
				$meta[ $key ][] = (string) (int) $mapped;
				continue;
			}
			$written = AB_MCP_Sync_Post_Writer::meta_value( $value, $plan, $changed );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$missing = AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], $pending );
			if ( array() !== $missing ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:reference_unmapped', AB_MCP_Sync_Post_Writer::missing_text( $missing ) );
			}
			$meta[ $key ][] = $written;
		}
		$terms = array();
		foreach ( $line['content']['terms'] as $term ) {
			$taxonomy = AB_MCP_Sync_Post_Writer::bytes( $term['taxonomy'] );
			if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:taxonomy_rule', sprintf( 'taxonomy %s on the attachment', $taxonomy ) );
			}
			if ( isset( $term['term_order'] ) && 0 !== (int) $term['term_order'] ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:term_order', sprintf( 'the term %1$s of %2$s on the attachment of the staging site carries the term_order %3$d', AB_MCP_Sync_Post_Writer::bytes( $term['slug'] ), $taxonomy, (int) $term['term_order'] ) );
			}
			$mapped = AB_MCP_Sync_Refs::mapped_id( $plan, 'term', (int) $term['term_id'] );
			if ( null === $mapped && isset( $pending['term'][ (int) $term['term_id'] ] ) ) {
				$plan['missing']    = array();
				$terms[ $taxonomy ] = isset( $terms[ $taxonomy ] ) ? $terms[ $taxonomy ] : array();
				continue;
			}
			if ( null === $mapped ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:reference_unmapped', sprintf( 'term %d of the taxonomy %s of the staging site', (int) $term['term_id'], $taxonomy ) );
			}
			$terms[ $taxonomy ][] = (int) $mapped;
		}
		foreach ( $terms as $taxonomy => $list ) {
			sort( $terms[ $taxonomy ] );
		}
		// The file list of the target: as the line names it, and as the core
		// works it out of the target's meta here (paths()): both the same, or
		// the package holds another list than its meta names.
		$files = self::files_of( $line['form']['files'] );
		if ( null === $files ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', 'the file list of the package is not one the staging site writes' );
		}
		$uploads = AB_MCP_Sync_Media::uploads();
		if ( '' !== $uploads['problem'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'by_code' === $uploads['problem'] ? 'attachment:uploads_by_code' : 'attachment:unsafe_path', sprintf( 'the uploads of this site (%s)', $uploads['cause'] ) );
		}
		$attached = isset( $meta['_wp_attached_file'][0] ) && is_string( $meta['_wp_attached_file'][0] ) ? $meta['_wp_attached_file'][0] : '';
		$listed   = AB_MCP_Sync_Media::paths(
			$attached,
			isset( $meta['_wp_attachment_metadata'][0] ) && is_array( $meta['_wp_attachment_metadata'][0] ) ? $meta['_wp_attachment_metadata'][0] : null,
			isset( $meta['_wp_attachment_backup_sizes'][0] ) && is_array( $meta['_wp_attachment_backup_sizes'][0] ) ? $meta['_wp_attachment_backup_sizes'][0] : null,
			$uploads['dir']
		);
		if ( array() !== $listed['problems'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'attachment:unsafe_path', sprintf( 'the file list the meta of the attachment names on this site (%s)', (string) $listed['problems'][0]['problem'] ) );
		}
		$named = array_column( $listed['files'], 'path' );
		sort( $named, SORT_STRING );
		if ( array_column( $files, 'path' ) !== $named ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', 'the file list of the package is not the one the meta of the attachment names' );
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
			'post_type'             => self::TYPE,
			'post_mime_type'        => $row['post_mime_type'],
			'edit_date'             => true,
		);
		if ( $create && '' !== $attached ) {
			// As an upload makes it: the file with the row, the guid the
			// address of the file (media_handle_upload()).
			$postarr['file'] = $attached;
			$url             = AB_MCP_Sync_Rows::uploads_url();
			if ( null !== $url ) {
				$postarr['guid'] = rtrim( (string) $url, '/' ) . '/' . $attached;
			}
		}
		$columns = array_intersect_key( $postarr, array_flip( AB_MCP_Sync_Post_Writer::WRITTEN_COLUMNS ) );
		if ( '' === $columns['post_date_gmt'] || '0000-00-00 00:00:00' === $columns['post_date_gmt'] ) {
			$columns['post_date_gmt'] = get_gmt_from_date( $columns['post_date'] );
		}
		$columns['post_author'] = (string) $columns['post_author'];
		$columns['post_parent'] = (string) $columns['post_parent'];
		$columns['menu_order']  = (string) $columns['menu_order'];
		$target                 = AB_MCP_Sync_Canon::mapped( $line['form'], $ctx['map'] );
		$staging                = null;
		if ( $later && isset( $target['row'] ) && is_array( $target['row'] ) ) {
			// Unattached until the parent step: the target of this step, and
			// the staging base of its pair, with no parent.
			$target['row']['post_parent'] = '0';
			$form                         = $line['form'];
			if ( isset( $form['row'] ) && is_array( $form['row'] ) ) {
				$form['row']['post_parent'] = '0';
			}
			$staging = AB_MCP_Sync_Canon::hash( $form );
		}
		return array(
			'type'             => self::TYPE,
			'op'               => $create ? 'create' : 'update',
			'status'           => $status,
			'postarr'          => $postarr,
			'columns'          => $columns,
			'meta'             => $meta,
			'terms'            => $terms,
			'files'            => $files,
			'media'            => isset( $ctx['media'] ) && is_array( $ctx['media'] ) ? $ctx['media'] : null,
			'staging_ref'      => isset( $item['staging_ref'] ) ? (string) $item['staging_ref'] : '',
			'target_form'      => $target,
			'target_hash'      => AB_MCP_Sync_Canon::hash( $target ),
			'identity'         => $line['identity'],
			'as_draft'         => false,
			'pending'          => $later,
			'parent_later'     => $later,
			'base_staging'     => $staging,
			'template_pending' => false,
			'planned'          => array(),
		);
	}

	/**
	 * The checks before the first part (decision 17, step 4): the rights of
	 * the account (upload_files for a new attachment and for new files or a
	 * change of the keys that name them, edit_post for one here), its meta by
	 * the rules of the class, the sanitizers ahead, the slug, the terms, and
	 * the files (files_refusal()).
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The attachment here, null for a new one.
	 * @param int      $now      Time.
	 * @return true|WP_Error
	 */
	public static function precheck( array $prepared, $live_id, $now ) {
		unset( $now );
		$pto = get_post_type_object( self::TYPE );
		if ( ! is_object( $pto ) || ! isset( $pto->cap ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:type_unregistered', 'the post type attachment is not registered on this site' );
		}
		$live    = null;
		$current = null;
		if ( null !== $live_id ) {
			$live = AB_MCP_Sync_Rows::post( (int) $live_id );
			if ( ! is_array( $live ) || self::TYPE !== (string) $live['post_type'] ) {
				return new WP_Error( 'ab_mcp_sync_target_gone', sprintf( 'The attachment %d of this site is no longer there, so nothing is written for it. Make a new report (wp_site_diff) and plan again.', (int) $live_id ) );
			}
			if ( ! current_user_can( 'edit_post', (int) $live_id ) ) {
				return AB_MCP_Sync_Post_Writer::rights( sprintf( 'edit the attachment %d (edit_post)', (int) $live_id ) );
			}
			$current = AB_MCP_Sync_Post_Writer::current( (int) $live_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
		} elseif ( ! current_user_can( (string) $pto->cap->create_posts ) ) {
			return AB_MCP_Sync_Post_Writer::rights( sprintf( 'upload a file (%s)', (string) $pto->cap->create_posts ) );
		}
		if ( 'private' === $prepared['status'] && ( null === $live || 'private' !== (string) $live['post_status'] ) && ! current_user_can( (string) $pto->cap->publish_posts ) ) {
			return AB_MCP_Sync_Post_Writer::rights( sprintf( 'make an attachment private (%s)', (string) $pto->cap->publish_posts ) );
		}
		if ( (int) $prepared['postarr']['post_author'] !== (int) get_current_user_id() && ! current_user_can( (string) $pto->cap->edit_others_posts ) ) {
			return AB_MCP_Sync_Post_Writer::rights( sprintf( 'write an attachment of another author (%s)', (string) $pto->cap->edit_others_posts ) );
		}
		// The meta (decision 28): of an attachment here, each key whose values
		// differ; of a new one, every key of the target.
		$keys = is_array( $current ) ? AB_MCP_Sync_Post_Writer::differing( $prepared, $current ) : array_keys( $prepared['meta'] );
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( in_array( $key, self::FILE_META, true ) && null !== $live_id && ! current_user_can( 'upload_files' ) ) {
				return AB_MCP_Sync_Post_Writer::rights( sprintf( 'change the files of the attachment %1$d (upload_files, for %2$s)', (int) $live_id, $key ) );
			}
			if ( in_array( $key, self::OWN_META, true ) || '_thumbnail_id' === $key ) {
				continue;
			}
			if ( is_array( $current ) ) {
				$may = AB_MCP_Sync_Post_Writer::meta_right( (int) $live_id, $key, AB_MCP_Sync_Post_Writer::targets( $prepared, $key ), AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) );
				if ( true !== $may ) {
					return AB_MCP_Sync_Post_Writer::locked( '*:meta_without_rule', sprintf( 'meta key %1$s of the attachment on this site: %2$s', $key, $may ) );
				}
			}
			if ( null !== AB_MCP_Builders::markup_meta_refusal( $key ) ) {
				return AB_MCP_Sync_Post_Writer::rights( sprintf( 'write the page-builder meta key %s (unfiltered_html)', $key ) );
			}
		}
		// The poster of an audio or a video: set_post_thumbnail() keeps an
		// image WordPress shows only (as for the featured image of a post).
		if ( in_array( '_thumbnail_id', $keys, true ) && isset( $prepared['meta']['_thumbnail_id'][0] ) && '0' !== (string) $prepared['meta']['_thumbnail_id'][0] ) {
			$image = (int) $prepared['meta']['_thumbnail_id'][0];
			if ( self::TYPE !== AB_MCP_Sync_Rows::post_type_of( $image ) || '' === (string) wp_get_attachment_image( $image, 'thumbnail' ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:thumbnail', sprintf( 'the attachment %d of this site the attachment shows is no image WordPress shows', $image ) );
			}
		}
		// Every written value through sanitize_meta(), by the bytes it stores.
		foreach ( $keys as $key ) {
			foreach ( isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array() as $value ) {
				if ( AB_MCP_Sync_Post_Writer::db_form( sanitize_meta( (string) $key, $value, 'post', self::TYPE ) ) !== AB_MCP_Sync_Post_Writer::stored_form( $value ) ) {
					return AB_MCP_Sync_Post_Writer::locked( 'post:sanitizer', sprintf( 'meta key %s (sanitize_meta() changes its value)', $key ) );
				}
			}
		}
		// The terms: registered for attachments here, the right to assign them.
		$taxonomies = array_keys( $prepared['terms'] );
		foreach ( is_array( $current ) ? $current['terms'] : array() as $term ) {
			$taxonomies[] = (string) $term['taxonomy'];
			if ( 0 !== (int) $term['term_order'] ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:term_order', sprintf( 'the term %1$s of %2$s on the attachment of this site carries the term_order %3$d', (string) $term['slug'], (string) $term['taxonomy'], (int) $term['term_order'] ) );
			}
		}
		foreach ( array_unique( $taxonomies ) as $taxonomy ) {
			if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( self::TYPE, $taxonomy ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:taxonomy_rule', sprintf( 'the taxonomy %s is not one this version writes for attachments on this site', $taxonomy ) );
			}
			$may = AB_MCP_Sync_Post_Writer::term_right( $taxonomy, isset( $prepared['terms'][ $taxonomy ] ) ? $prepared['terms'][ $taxonomy ] : array() );
			if ( true !== $may ) {
				return AB_MCP_Sync_Post_Writer::rights( $may );
			}
		}
		if ( null !== $live_id ) {
			$refusal = AB_MCP_Sync_Post_Writer::spelling_refusal( (int) $live_id, $keys );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		// The slug: unique across every type for an attachment
		// (wp_unique_post_slug()), as wp_insert_post() makes it.
		$refusal = AB_MCP_Sync_Post_Writer::slug_refusal( $prepared, $live_id, is_array( $live ) ? (string) $live['post_name'] : null );
		if ( null !== $refusal ) {
			return $refusal;
		}
		// The sanitizers ahead (decision 17), as for a post.
		$fields    = array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered', 'comment_status', 'ping_status', 'post_mime_type' );
		$slashed   = wp_slash( array_intersect_key( $prepared['postarr'], array_flip( array_merge( $fields, array( 'post_name' ) ) ) ) );
		$sanitized = (array) sanitize_post( $slashed + array( 'post_type' => self::TYPE ), 'db' );
		foreach ( $fields as $field ) {
			if ( ! isset( $sanitized[ $field ] ) || (string) $sanitized[ $field ] !== (string) $slashed[ $field ] ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:sanitizer', sprintf( 'field %s', $field ) );
			}
		}
		$plan = self::files_plan( $prepared, $live_id );
		return is_wp_error( $plan ) ? $plan : true;
	}

	/* -------------------------------------------------------------- files */

	/**
	 * What the files of the target are on this site (decision 26), each by
	 * its path: present (the same file byte for byte), absent (to lay), or a
	 * refusal: another file, a link or anything else at the path or on the
	 * way to it (attachment:replace_file, attachment:unsafe_path), a path
	 * another attachment of this site names (attachment:shared_path), more
	 * bytes to lay than one package of media carries
	 * (attachment:file_too_large), a file of a type this account may not
	 * upload by its name, or a main file of another type than the attachment
	 * says (attachment:file_type; by its content as it is laid, lay_files()),
	 * a file to lay that no package of media of the run carries.
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The attachment here, null for a new one.
	 * @return array{root:string,files:array[]}|WP_Error files: path, sha256, size, state (present or absent), media (the entry of the index, absent ones).
	 */
	public static function files_plan( array $prepared, $live_id ) {
		$uploads = AB_MCP_Sync_Media::uploads();
		if ( '' !== $uploads['problem'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'by_code' === $uploads['problem'] ? 'attachment:uploads_by_code' : 'attachment:unsafe_path', sprintf( 'the uploads of this site (%s)', $uploads['cause'] ) );
		}
		$dir  = rtrim( (string) $uploads['dir'], '/' );
		$root = realpath( $dir );
		if ( false === $root ) {
			// A site without an upload yet: the folder WordPress makes with the
			// first upload (wp_upload_dir()), made as the first file is laid,
			// under a folder that is there and no link.
			$parent = realpath( dirname( $dir ) );
			if ( false === $parent || ! is_dir( $parent ) || is_link( $dir ) || file_exists( $dir ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'attachment:unsafe_path', 'the uploads folder of this site is not there, and neither is the folder it would be made in' );
			}
			$root = $parent . '/' . basename( $dir );
		} elseif ( ! is_dir( $root ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'attachment:unsafe_path', 'the uploads folder of this site is no folder' );
		}
		$out   = array();
		$lay   = 0;
		$mimes = get_allowed_mime_types( wp_get_current_user() );
		$free  = current_user_can( 'unfiltered_upload' );
		foreach ( $prepared['files'] as $file ) {
			$state = AB_MCP_Sync_Media::target_state( $root, $file['path'] );
			if ( 'file' === $state ) {
				$sha   = @hash_file( 'sha256', $root . '/' . $file['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed read is answered.
				$state = is_string( $sha ) && hash_equals( (string) $file['sha256'], $sha ) && (int) @filesize( $root . '/' . $file['path'] ) === (int) $file['size'] ? 'present' : 'other'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( 'unsafe' === $state ) {
				return AB_MCP_Sync_Post_Writer::locked( 'attachment:unsafe_path', sprintf( 'file %s: a link or no folder lies on the way to it on this site', $file['path'] ) );
			}
			if ( 'other' === $state ) {
				return AB_MCP_Sync_Post_Writer::locked( 'attachment:replace_file', sprintf( 'file %s: this site has another file there (or a link, or a folder), which is never written over', $file['path'] ) );
			}
			$entry = array(
				'path'   => $file['path'],
				'sha256' => $file['sha256'],
				'size'   => $file['size'],
				'state'  => $state,
			);
			if ( 'absent' === $state ) {
				$lay += (int) $file['size'];
				$type = wp_check_filetype( self::base( $file['path'] ), $mimes );
				if ( ( empty( $type['type'] ) || empty( $type['ext'] ) ) && ! $free ) {
					return AB_MCP_Sync_Post_Writer::locked( 'attachment:file_type', sprintf( 'file %s: a type this account may not upload', $file['path'] ) );
				}
				$key = (string) $prepared['staging_ref'] . "\0" . $file['path'];
				if ( ! is_array( $prepared['media'] ) || ! isset( $prepared['media'][ $key ] ) || ! hash_equals( (string) $file['sha256'], (string) $prepared['media'][ $key ]['sha256'] ) || (int) $file['size'] !== (int) $prepared['media'][ $key ]['size'] ) {
					return new WP_Error( 'ab_mcp_sync_media_missing', sprintf( 'The packages of media of this run hold no file %1$s of the attachment %2$s of the staging site as the report compared it, and this site does not have it. Export the files there (wp_site_export with purpose=media, the report_hash and this attachment), take the package over here (wp_site_import_chunk with purpose=media) and plan again with it in packages.', $file['path'], (string) $prepared['staging_ref'] ) );
				}
				$entry['media'] = $prepared['media'][ $key ];
			}
			$out[] = $entry;
		}
		if ( $lay > self::MAX_BYTES ) {
			return AB_MCP_Sync_Post_Writer::locked( 'attachment:file_too_large', sprintf( '%1$d bytes to lay, %2$d at most', $lay, self::MAX_BYTES ) );
		}
		// The main file of the type the attachment says (post_mime_type), by its
		// name; where the step makes the attachment name a file this site has
		// already (a new attachment, or another main file), by its content as
		// well, as the way back asks it of the file it names again
		// (restore_right()); a file to lay is asked so as it is laid (provide()).
		$attached = isset( $prepared['meta']['_wp_attached_file'][0] ) ? (string) $prepared['meta']['_wp_attached_file'][0] : '';
		if ( '' !== $attached ) {
			$type = wp_check_filetype( self::base( $attached ), $mimes );
			if ( ! $free && (string) $type['type'] !== (string) $prepared['postarr']['post_mime_type'] ) {
				return AB_MCP_Sync_Post_Writer::locked( 'attachment:file_type', sprintf( 'the file %1$s is %2$s by its name, the attachment says %3$s', $attached, '' === (string) $type['type'] ? 'of no type this account may upload' : (string) $type['type'], (string) $prepared['postarr']['post_mime_type'] ) );
			}
			$current = null === $live_id ? null : AB_MCP_Sync_Post_Writer::current( (int) $live_id );
			$names   = ! is_array( $current ) || AB_MCP_Sync_Post_Writer::meta_values( $current, '_wp_attached_file' ) !== array( $attached );
			foreach ( $out as $entry ) {
				if ( $names && 'present' === $entry['state'] && $entry['path'] === $attached ) {
					$check = wp_check_filetype_and_ext( $root . '/' . $attached, self::base( $attached ), $mimes );
					if ( ! empty( $check['proper_filename'] ) || ( ( empty( $check['type'] ) || empty( $check['ext'] ) || (string) $check['type'] !== (string) $prepared['postarr']['post_mime_type'] ) && ! $free ) ) {
						return AB_MCP_Sync_Post_Writer::locked( 'attachment:file_type', sprintf( 'the file %s of this site the attachment would name is by its content of another type than its name and the attachment say, or of a type this account may not upload', $attached ) );
					}
				}
			}
		}
		// Another attachment of this site that names a path of the list.
		$named = AB_MCP_Sync_Media::named_by( array_column( $prepared['files'], 'path' ), null === $live_id ? 0 : (int) $live_id, self::SHARED_MAX );
		if ( null === $named ) {
			return AB_MCP_Sync_Post_Writer::locked( 'attachment:shared_path', 'the file lists of the other attachments of this site could not be read whole' );
		}
		foreach ( $named as $path => $ids ) {
			return AB_MCP_Sync_Post_Writer::locked( 'attachment:shared_path', sprintf( 'file %1$s: the attachment %2$d of this site names it as well', (string) $path, (int) $ids[0] ) );
		}
		return array(
			'root'  => $root,
			'files' => $out,
		);
	}

	/**
	 * Lay the files of a step (decision 26), before its row: the list
	 * checked again (files_plan()), into the journal ahead (files: each with
	 * path, sha256, size, state, own); every file to lay first provided whole
	 * in a temporary file of this request from the packages of media of the
	 * run and checked by its content (provide()); then each laid exclusively
	 * under its name, the journal saved before each (creating) and after it
	 * (laid, own). Where anything fails, nothing more is laid, the files laid
	 * before stay as the run's own, and the step writes no row.
	 *
	 * @param int   $run_id   Run.
	 * @param int   $seq      Step.
	 * @param array $journal  Journal (its parts written ahead).
	 * @param array $prepared target_of().
	 * @param array $lines    AB_MCP_Sync_Engine::lines() of the run (its media).
	 * @param int|null $live_id The attachment here, null for a new one.
	 * @return array{ok:bool,journal:array,message:string}
	 */
	public static function lay_files( $run_id, $seq, array $journal, array $prepared, array $lines, $live_id ) {
		$save = static function ( array $journal ) use ( $run_id, $seq ) {
			return AB_MCP_Sync_Run::update_step( (int) $run_id, (int) $seq, array( 'parts' => $journal ), 'begun' );
		};
		$plan = self::files_plan( $prepared, $live_id );
		if ( is_wp_error( $plan ) ) {
			return self::not_laid( $journal, $plan->get_error_message() );
		}
		$journal['files'] = array();
		$watch            = array();
		foreach ( $plan['files'] as $file ) {
			$journal['files'][] = array(
				'path'   => $file['path'],
				'sha256' => $file['sha256'],
				'size'   => $file['size'],
				'state'  => 'present' === $file['state'] ? 'present' : 'planned',
				'own'    => false,
			);
			$watch[ (string) $file['path'] ] = true;
		}
		// The files the object names before and after the step, read with
		// every reading of its state (files_state(); Codex round 1, A3): the
		// list of the target and that of the attachment here.
		if ( null !== $live_id ) {
			$here = AB_MCP_Sync_Media::paths_of( (int) $live_id );
			if ( null === $here ) {
				return self::not_laid( $journal, 'the file list of the attachment on this site does not read whole, so its files cannot be compared as the step writes' );
			}
			foreach ( $here as $path ) {
				$watch[ (string) $path ] = true;
			}
		}
		$watch = array_map( 'strval', array_keys( $watch ) );
		sort( $watch, SORT_STRING );
		$journal['watch'] = $watch;
		if ( true !== $save( $journal ) ) {
			return self::not_laid( $journal, 'the journal of the step could not be written ahead of its files, so none was laid' );
		}
		$temp = array();
		try {
			// Provided whole first: every file to lay in a temporary file,
			// checked against its size, its SHA-256 and its content.
			$taken = array();
			foreach ( $plan['files'] as $i => $file ) {
				if ( 'absent' !== $file['state'] ) {
					continue;
				}
				$package = (string) $file['media']['package'];
				if ( ! isset( $taken[ $package ] ) ) {
					$taken[ $package ] = AB_MCP_Sync_Import::taken( $package, 'media' );
				}
				if ( is_wp_error( $taken[ $package ] ) ) {
					return self::not_laid( $journal, $taken[ $package ]->get_error_message() );
				}
				$made = self::provide( $taken[ $package ], $file, $prepared );
				if ( is_wp_error( $made ) ) {
					$journal['files'][ $i ]['state'] = 'failed';
					$journal['files'][ $i ]['note']  = $made->get_error_message();
					$save( $journal );
					return self::not_laid( $journal, $made->get_error_message() );
				}
				$temp[ $i ] = $made;
			}
			// Then laid, one after the other, each exclusively.
			foreach ( $plan['files'] as $i => $file ) {
				if ( 'absent' !== $file['state'] ) {
					continue;
				}
				// Ahead of the folder and the file: whatever this request makes
				// from here on stands in the journal (creating: unclear until the
				// file is shown laid, never the run's own by that state alone).
				// The rights as the check before asked them, again right before
				// the file (Codex round 1, A5: a filter that ran as the files were
				// provided may have changed them): nothing is laid without them.
				$may = self::may_lay( $live_id, $file );
				if ( true !== $may ) {
					return self::not_laid( $journal, $may );
				}
				$journal['files'][ $i ]['state'] = 'creating';
				if ( true !== $save( $journal ) ) {
					$journal['files'][ $i ]['state'] = 'planned';
					return self::not_laid( $journal, sprintf( 'the journal of the step could not be saved before the file %s, so it was not laid', $file['path'] ) );
				}
				// The folder, then the file, with no save of the journal in
				// between (Codex round 1, A1).
				$dir = self::folder( $plan['root'], $file['path'] );
				if ( is_wp_error( $dir ) ) {
					$journal['files'][ $i ]['state'] = 'failed';
					$journal['files'][ $i ]['note']  = $dir->get_error_message();
					$save( $journal );
					return self::not_laid( $journal, $dir->get_error_message() );
				}
				$laid = self::lay( $plan['root'], $plan['root'] . '/' . $file['path'], $temp[ $i ], $file );
				$journal['files'][ $i ]['state'] = $laid['state'];
				$journal['files'][ $i ]['own']   = $laid['own'];
				if ( '' !== $laid['note'] ) {
					$journal['files'][ $i ]['note'] = $laid['note'];
				}
				$saved = $save( $journal );
				if ( 'laid' !== $laid['state'] ) {
					return self::not_laid( $journal, $laid['note'] );
				}
				if ( true !== $saved ) {
					return self::not_laid( $journal, sprintf( 'the journal of the step could not be saved after the file %s, so no further file was laid', $file['path'] ) );
				}
				AB_MCP_Change_Lock::touch();
			}
			// The files as they lie now, the first reading the calls of the
			// step are compared against (files_state()).
			$journal['files_seen'] = self::files_state( $journal );
			if ( true !== $save( $journal ) ) {
				return self::not_laid( $journal, 'the journal of the step could not be saved after its files, so its row was not written' );
			}
		} finally {
			foreach ( $temp as $file ) {
				// The temporary copies of this request, outside the uploads.
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		return array(
			'ok'      => true,
			'journal' => $journal,
			'message' => '',
		);
	}

	/**
	 * The files of a journal the run laid itself (own): path and SHA-256, for
	 * the result of a step and of its way back (decision 26: they stay, named
	 * with their paths). Own is only what stands with own after the file was
	 * made exclusively and shown at its path (lay()); a file the journal
	 * shows as creating (the request ended as it was made) or unclear is
	 * never own (Codex round 1, A2: unclear_files()).
	 *
	 * @param array $journal Journal.
	 * @return array[]
	 */
	public static function own_files( array $journal ) {
		$out = array();
		foreach ( isset( $journal['files'] ) && is_array( $journal['files'] ) ? $journal['files'] : array() as $file ) {
			if ( ! empty( $file['own'] ) && in_array( (string) ( $file['state'] ?? '' ), array( 'laid', 'partial' ), true ) ) {
				$out[] = array(
					'path'   => (string) $file['path'],
					'sha256' => (string) $file['sha256'],
					'state'  => (string) $file['state'],
				);
			}
		}
		return $out;
	}

	/**
	 * What lies at each path a step watches (watch in its journal: the file
	 * list of its target and that of the attachment here before), read as
	 * one state of the object beside its rows (Codex round 1 of branch 10,
	 * A3): absent, the SHA-256 and the size of a plain file, or what else
	 * lies there (other, unsafe), unreadable where it does not read. The
	 * engine compares it after every call of the step's own and every part
	 * the way back puts back (a file a hook changed deviates the object).
	 *
	 * @param array $journal Journal.
	 * @return array<string,string>|null Path => state; null without watch.
	 */
	public static function files_state( array $journal ) {
		if ( ! isset( $journal['watch'] ) || ! is_array( $journal['watch'] ) ) {
			return null;
		}
		$uploads = AB_MCP_Sync_Media::uploads();
		$root    = '' === $uploads['problem'] ? realpath( $uploads['dir'] ) : false;
		$out     = array();
		foreach ( $journal['watch'] as $path ) {
			$path = (string) $path;
			if ( false === $root ) {
				$out[ $path ] = 'unreadable';
				continue;
			}
			$state = AB_MCP_Sync_Media::target_state( $root, $path );
			if ( 'file' === $state ) {
				$full = $root . '/' . $path;
				clearstatcache( true, $full );
				$sha   = @hash_file( 'sha256', $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed read is answered.
				$size  = @filesize( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$state = is_string( $sha ) && false !== $size ? $sha . ':' . (int) $size : 'unreadable';
			}
			$out[ $path ] = $state;
		}
		return $out;
	}

	/**
	 * Whether this account may lay a file of the step now, as the check
	 * before the first write asked it (precheck(), files_plan()): a new
	 * attachment with the right to make one (upload_files, the create_posts
	 * of the type), one here with edit_post and upload_files; the file of a
	 * type this account may upload by its name (unfiltered_upload any).
	 *
	 * @param int|null $live_id The attachment here, null for a new one.
	 * @param array    $file    An entry of files_plan().
	 * @return true|string Why not, as a clause.
	 */
	private static function may_lay( $live_id, array $file ) {
		$pto = get_post_type_object( self::TYPE );
		if ( ! is_object( $pto ) || ! isset( $pto->cap ) ) {
			return 'the post type attachment is not registered on this site';
		}
		if ( null === $live_id ? ! current_user_can( (string) $pto->cap->create_posts ) : ( ! current_user_can( 'edit_post', (int) $live_id ) || ! current_user_can( 'upload_files' ) ) ) {
			return sprintf( 'this account may not upload files here any more (%s), so the file %s was not laid', null === $live_id ? (string) $pto->cap->create_posts : 'edit_post, upload_files', (string) $file['path'] );
		}
		if ( ! current_user_can( 'unfiltered_upload' ) ) {
			$type = wp_check_filetype( self::base( $file['path'] ), get_allowed_mime_types( wp_get_current_user() ) );
			if ( empty( $type['type'] ) || empty( $type['ext'] ) ) {
				return sprintf( 'the file %s is of a type this account may not upload any more', (string) $file['path'] );
			}
		}
		return true;
	}

	/**
	 * The files of a journal whose state is unclear, with their paths: one
	 * the journal shows as creating (the request ended between the journal
	 * and the file: whether a file lies there, and whose, only the disk
	 * says) and one made but not provably at its path (lay()). Never the
	 * run's own; named in the result of the step and of its way back, so a
	 * person checks them (nothing is deleted).
	 *
	 * @param array $journal Journal.
	 * @return array[] path, state, note.
	 */
	public static function unclear_files( array $journal ) {
		$out = array();
		foreach ( isset( $journal['files'] ) && is_array( $journal['files'] ) ? $journal['files'] : array() as $file ) {
			$state = (string) ( $file['state'] ?? '' );
			if ( 'creating' === $state || 'unclear' === $state ) {
				$out[] = array(
					'path'  => (string) $file['path'],
					'state' => 'unclear',
					'note'  => 'creating' === $state ? sprintf( 'The request ended as the file %s was being made: whether a file lies there, and whose, only the uploads of this site say. It is not the run\'s own; check it by hand.', (string) $file['path'] ) : (string) ( $file['note'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * One file provided whole: from its package into a temporary file of
	 * this request, checked against its size and SHA-256
	 * (AB_MCP_Sync_Media_Package::extract()) and by its content, with the
	 * types this account may upload (wp_check_filetype_and_ext(), as the
	 * core checks an upload): a type it may not, or a content of another
	 * type than its name (a name the core would change), refuses it; the
	 * main file has the type the attachment says.
	 *
	 * @param array $taken    AB_MCP_Sync_Media_Package::taken().
	 * @param array $file     An entry of files_plan() (absent, with media).
	 * @param array $prepared target_of().
	 * @return string|WP_Error The temporary file.
	 */
	private static function provide( array $taken, array $file, array $prepared ) {
		$tmp = @tempnam( get_temp_dir(), 'abmcp' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure is answered.
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return new WP_Error( 'ab_mcp_sync_media_temp', 'No temporary file could be made for a file of the attachment, so nothing of it was laid. Ask the host to check that PHP may write to its temporary folder (get_temp_dir()), then call again.' );
		}
		$index = null;
		foreach ( $taken['files'] as $entry ) {
			if ( $entry['ref'] === (string) $prepared['staging_ref'] && $entry['path'] === $file['path'] ) {
				$index = $entry;
			}
		}
		$done = null === $index ? new WP_Error( 'ab_mcp_sync_media_missing', sprintf( 'The package of media holds no file %s of the attachment any more. Export the files again (wp_site_export with purpose=media) and plan again.', $file['path'] ) ) : AB_MCP_Sync_Media_Package::extract( $taken, $index, $tmp );
		if ( true === $done && ( ! hash_equals( (string) $file['sha256'], (string) $index['sha256'] ) || (int) $file['size'] !== (int) $index['size'] ) ) {
			$done = new WP_Error( 'ab_mcp_sync_media_missing', sprintf( 'The package of media holds the file %s of the attachment other than the report compared it. Export the files again (wp_site_export with purpose=media) and plan again.', $file['path'] ) );
		}
		if ( true === $done ) {
			$check = wp_check_filetype_and_ext( $tmp, self::base( $file['path'] ), get_allowed_mime_types( wp_get_current_user() ) );
			$main  = isset( $prepared['meta']['_wp_attached_file'][0] ) && (string) $prepared['meta']['_wp_attached_file'][0] === (string) $file['path'];
			if ( ! empty( $check['proper_filename'] ) ) {
				$done = AB_MCP_Sync_Post_Writer::locked( 'attachment:file_type', sprintf( 'file %1$s: its content is of another type than its name says (WordPress would store it as %2$s)', $file['path'], (string) $check['proper_filename'] ) );
			} elseif ( ( empty( $check['type'] ) || empty( $check['ext'] ) ) && ! current_user_can( 'unfiltered_upload' ) ) {
				$done = AB_MCP_Sync_Post_Writer::locked( 'attachment:file_type', sprintf( 'file %s: by its content a type this account may not upload', $file['path'] ) );
			} elseif ( $main && ! current_user_can( 'unfiltered_upload' ) && (string) $check['type'] !== (string) $prepared['postarr']['post_mime_type'] ) {
				$done = AB_MCP_Sync_Post_Writer::locked( 'attachment:file_type', sprintf( 'file %1$s: by its content %2$s, the attachment says %3$s', $file['path'], (string) $check['type'], (string) $prepared['postarr']['post_mime_type'] ) );
			}
		}
		if ( true !== $done ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- the temporary copy of this request.
			return $done;
		}
		return $tmp;
	}

	/**
	 * The folder of a file to lay, under the resolved uploads (decision 26;
	 * branch 10, Codex round 1, A1 and B8): the uploads folder itself first
	 * (a site without an upload yet: made in the folder files_plan()
	 * resolved), then each folder on the way, one level at a time: a link
	 * locks, a file where a folder goes locks, a missing one is made with
	 * mkdir() (never recursive, the permissions of the folder above, as
	 * wp_mkdir_p() takes them) and every level checked again by its resolved
	 * path, so a folder only counts where it resolves to itself under the
	 * uploads. A folder another hand made somewhere else in between stays as
	 * it is (nothing is deleted) and the file is not laid.
	 *
	 * @param string $root The resolved uploads.
	 * @param string $path The file, relative (AB_MCP_Sync_Media::safe_path()).
	 * @return true|WP_Error
	 */
	private static function folder( $root, $path ) {
		$parts = explode( '/', (string) $path );
		array_pop( $parts );
		$at = rtrim( (string) $root, '/' );
		foreach ( array_merge( array( '' ), $parts ) as $part ) {
			$above = '' === $part ? dirname( $at ) : $at;
			$at    = '' === $part ? $at : $at . '/' . $part;
			clearstatcache( true, $at );
			if ( is_link( $at ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'attachment:unsafe_path', sprintf( 'file %s: a link lies on the way to it', $path ) );
			}
			if ( ! file_exists( $at ) ) {
				$stat  = @stat( $above ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure is answered by the check below.
				$perms = is_array( $stat ) ? ( $stat['mode'] & 0007777 ) : 0755;
				if ( ! @mkdir( $at, $perms ) && ! is_dir( $at ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- one level under a checked folder, checked again below.
					return new WP_Error( 'ab_mcp_sync_media_folder', sprintf( 'The folder of the file %s could not be made in the uploads of this site, so it was not laid. Ask the host to check that the uploads are writable, then call again.', $path ) );
				}
				clearstatcache( true, $at );
			}
			if ( ! is_link( $at ) && file_exists( $at ) && ! is_dir( $at ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'attachment:replace_file', sprintf( 'file %s: a file lies where its folder would be', $path ) );
			}
			$real = realpath( $at );
			if ( is_link( $at ) || false === $real || $real !== $at || ! is_dir( $real ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'attachment:unsafe_path', sprintf( 'file %s: its folder does not resolve to itself under the uploads', $path ) );
			}
		}
		return true;
	}

	/**
	 * Lay one file exclusively (fopen 'xb': never over anything) from its
	 * temporary copy, and read it back (branch 10, Codex round 1, A1): right
	 * before the open its folder is asked again (it resolves to itself under
	 * the uploads, no link), with no save of the journal in between; right
	 * after it the file opened is shown to be the one at its path under the
	 * uploads (device and inode of the handle, fstat(), as those of the path,
	 * lstat(); the path and its folder resolve to themselves). Where it is
	 * not, nothing is written into it: it is closed and stays as it is, empty
	 * and named (unclear, never the run's own, nothing deleted).
	 *
	 * @param string $root The resolved uploads.
	 * @param string $full The path of the file.
	 * @param string $from The temporary copy.
	 * @param array  $file path, sha256, size.
	 * @return array{state:string,own:bool,note:string} state laid, failed (nothing laid: the path was taken, or its folder is no longer the checked one), unclear (a file was made, not provably at its path under the uploads) or partial (laid by the run, not as planned).
	 */
	private static function lay( $root, $full, $from, array $file ) {
		$root = rtrim( (string) $root, '/' );
		$dir  = dirname( (string) $full );
		clearstatcache();
		$real_dir = realpath( $dir );
		if ( is_link( $dir ) || false === $real_dir || $real_dir !== $dir || ! is_dir( $real_dir ) || ( $dir !== $root && 0 !== strpos( $dir, $root . '/' ) ) ) {
			return array(
				'state' => 'failed',
				'own'   => false,
				'note'  => sprintf( 'The file %s was not laid: its folder does not resolve to itself under the uploads any more (another hand changed it since the check); nothing was written.', $file['path'] ),
			);
		}
		if ( is_callable( self::$probe ) ) {
			call_user_func( self::$probe, (string) $full );
		}
		$out = @fopen( (string) $full, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- exclusive: a file there is never written over.
		if ( false === $out ) {
			return array(
				'state' => 'failed',
				'own'   => false,
				'note'  => sprintf( 'The file %s was not laid: the path is taken on this site since the check (another file, a link), or the folder is not writable; nothing there was written over.', $file['path'] ),
			);
		}
		$opened = fstat( $out );
		clearstatcache();
		$there  = @lstat( (string) $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure is answered.
		$placed = is_array( $opened ) && is_array( $there ) && (int) $opened['dev'] === (int) $there['dev'] && (int) $opened['ino'] === (int) $there['ino'] && realpath( (string) $full ) === (string) $full && realpath( $dir ) === $dir;
		if ( ! $placed ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array(
				'state' => 'unclear',
				'own'   => false,
				'note'  => sprintf( 'A file was made for %s, but it is not provably the one at that path under the uploads (another hand changed a folder on the way as it was opened): nothing was written into it, it stays as it is, empty; check the uploads and the folders around them on this site by hand.', $file['path'] ),
			);
		}
		$in   = @fopen( (string) $from, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$done = false === $in ? false : stream_copy_to_stream( $in, $out );
		if ( false !== $in ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$flushed = fflush( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		clearstatcache( true, (string) $full );
		$sha = @hash_file( 'sha256', (string) $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed read is answered.
		if ( false === $done || ! $flushed || ! is_string( $sha ) || ! hash_equals( (string) $file['sha256'], $sha ) || (int) @filesize( (string) $full ) !== (int) $file['size'] ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'state' => 'partial',
				'own'   => true,
				'note'  => sprintf( 'The file %s was made by the run but does not read back whole (the disk, or a hook of this site); it stays as the run\'s own.', $file['path'] ),
			);
		}
		return array(
			'state' => 'laid',
			'own'   => true,
			'note'  => '',
		);
	}

	/**
	 * The answer of lay_files() where a file was not laid.
	 *
	 * @param array  $journal Journal.
	 * @param string $why     Why, as a clause or a sentence.
	 * @return array{ok:bool,journal:array,message:string}
	 */
	private static function not_laid( array $journal, $why ) {
		$own     = self::own_files( $journal );
		$unclear = self::unclear_files( $journal );
		return array(
			'ok'      => false,
			'state'   => array() === $unclear ? 'failed' : 'unclear',
			'journal' => $journal,
			'message' => sprintf( 'The files of the attachment were not laid whole: %1$s. Its row was not written%2$s%3$s.', rtrim( (string) $why, '.' ), array() === $own ? '' : sprintf( '; the %d file(s) the run laid before stay as its own (files, with their paths): nothing is deleted, they are tidied with the manifest of the files (Z8b), or by hand', count( $own ) ), array() === $unclear ? '' : sprintf( '; %d file(s) are unclear (files_unclear, with their paths): check them by hand', count( $unclear ) ) ),
		);
	}

	/**
	 * The file list of a line checked: each a plain path under the uploads,
	 * a SHA-256 in hex and a size; sorted by path, each once.
	 *
	 * @param mixed $files The form's files.
	 * @return array[]|null
	 */
	private static function files_of( $files ) {
		if ( ! is_array( $files ) || ! array_is_list( $files ) ) {
			return null;
		}
		$out = array();
		foreach ( $files as $file ) {
			$path = is_array( $file ) && isset( $file['path'] ) ? AB_MCP_Sync_Post_Writer::bytes( $file['path'] ) : '';
			if ( ! is_array( $file ) || array( 'path', 'sha256', 'size' ) !== self::sorted_keys( $file ) || ! AB_MCP_Sync_Media::safe_path( $path ) || ! is_string( $file['sha256'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $file['sha256'] ) || ! is_int( $file['size'] ) || $file['size'] < 0 || isset( $out[ $path ] ) ) {
				return null;
			}
			$out[ $path ] = array(
				'path'   => $path,
				'sha256' => $file['sha256'],
				'size'   => $file['size'],
			);
		}
		ksort( $out, SORT_STRING );
		return array_values( $out );
	}

	/**
	 * @param string $path A path.
	 * @return string Its last segment.
	 */
	private static function base( $path ) {
		$path = (string) $path;
		$at   = strrpos( $path, '/' );
		return false === $at ? $path : (string) substr( $path, $at + 1 );
	}

	/**
	 * @param array $array Array.
	 * @return string[] Its keys, sorted.
	 */
	private static function sorted_keys( array $array ) {
		$keys = array_map( 'strval', array_keys( $array ) );
		sort( $keys );
		return $keys;
	}

	/* ----------------------------------------------- the parts of a step */

	/**
	 * Whether this account may put a part back as the way back writes it:
	 * the rights the forward write asks (decision 28; jede Prüfung, die
	 * vorwärts gilt, gilt auch auf dem Rückweg): the row and any other key as
	 * for a post (AB_MCP_Sync_Post_Writer::restore_right()); an own key with
	 * edit_post, one that names files with upload_files as well, and the file
	 * the backed-up _wp_attached_file names of a type this account may upload
	 * by its content, there on this site (nothing is laid on the way back).
	 *
	 * @param string $part    Part.
	 * @param int    $live_id Attachment.
	 * @param array  $backup  current() of the backup.
	 * @param array  $now     current() as read right before the part's turn.
	 * @return true|string
	 */
	public static function restore_right( $part, $live_id, array $backup, array $now ) {
		$part = (string) $part;
		$key  = 0 === strpos( $part, 'meta:' ) ? substr( $part, 5 ) : null;
		if ( null === $key || ! in_array( $key, self::OWN_META, true ) ) {
			return AB_MCP_Sync_Post_Writer::restore_right( $part, $live_id, $backup, $now );
		}
		$id = (int) $live_id;
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return sprintf( 'edit the attachment %1$d (edit_post, for the part %2$s)', $id, $part );
		}
		if ( in_array( $key, self::FILE_META, true ) && ! current_user_can( 'upload_files' ) ) {
			return sprintf( 'change the files of the attachment %1$d (upload_files, for the part %2$s)', $id, $part );
		}
		if ( '_wp_attached_file' === $key ) {
			$values = AB_MCP_Sync_Post_Writer::meta_values( $backup, $key );
			if ( array() !== $values ) {
				$uploads = AB_MCP_Sync_Media::uploads();
				$root    = '' === $uploads['problem'] ? realpath( $uploads['dir'] ) : false;
				$file    = (string) $values[0];
				if ( false === $root || ! AB_MCP_Sync_Media::safe_path( $file ) || 'file' !== AB_MCP_Sync_Media::target_state( $root, $file ) ) {
					return sprintf( 'name the file %s again: it is not a plain file under the uploads of this site any more', $file );
				}
				$check = wp_check_filetype_and_ext( $root . '/' . $file, self::base( $file ), get_allowed_mime_types( wp_get_current_user() ) );
				if ( ! empty( $check['proper_filename'] ) || ( ( empty( $check['type'] ) || empty( $check['ext'] ) ) && ! current_user_can( 'unfiltered_upload' ) ) ) {
					return sprintf( 'name the file %s again (by its content a type this account may not upload)', $file );
				}
			}
		}
		return true;
	}

	/**
	 * Whether the attachment here is one this version writes as it is: its
	 * canonical form carries no lock, and no term of a taxonomy outside the
	 * rule.
	 *
	 * @param int   $live_id Attachment.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return true|WP_Error
	 */
	public static function live_rule( $live_id, array $source ) {
		$current = AB_MCP_Sync_Post_Writer::current( $live_id );
		if ( is_wp_error( $current ) || null === $current ) {
			return is_wp_error( $current ) ? $current : true;
		}
		$held = AB_MCP_Sync_Post_Writer::read_lock( self::live_read( (int) $live_id, $source ), 'the attachment of this site' );
		if ( null !== $held ) {
			return $held;
		}
		foreach ( $current['terms'] as $term ) {
			if ( ! in_array( $term['taxonomy'], self::TAXONOMIES, true ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:taxonomy_rule', sprintf( 'taxonomy %s of the attachment on this site', $term['taxonomy'] ) );
			}
		}
		return true;
	}

	/**
	 * @param array      $prepared target_of().
	 * @param array|null $current  current() of the attachment here; null for a new one.
	 * @return array[] As AB_MCP_Sync_Post_Writer::journal_parts().
	 */
	public static function journal_parts( array $prepared, $current ) {
		return AB_MCP_Sync_Post_Writer::journal_parts( $prepared, $current );
	}

	/**
	 * Whether a part is written by the row call: the file of a new
	 * attachment (wp_insert_attachment() with the file, update_attached_file()).
	 *
	 * @param array  $prepared target_of().
	 * @param string $name     The name of the part.
	 * @return bool
	 */
	public static function through_row( array $prepared, $name ) {
		return 'meta:_wp_attached_file' === (string) $name && 'create' === $prepared['op'] && isset( $prepared['postarr']['file'] );
	}

	/**
	 * The parts the row call writes besides the row: the file of a new
	 * attachment, the hash of its path.
	 *
	 * @param array      $prepared target_of(); or type and an empty postarr for the row put back.
	 * @param int|null   $live_id  The attachment here, null for a new one.
	 * @param array|null $current  current() right before the row call.
	 * @return array<string,string>
	 */
	public static function with_row( array $prepared, $live_id, $current = null ) {
		unset( $current );
		if ( null === $live_id && isset( $prepared['postarr']['file'] ) && '' !== (string) $prepared['postarr']['file'] ) {
			return array( 'meta:_wp_attached_file' => AB_MCP_Sync_Post_Writer::meta_hash( array( (string) $prepared['postarr']['file'] ) ) );
		}
		return array();
	}

	/**
	 * Write the row: wp_insert_attachment() with its file for a new one (as
	 * an upload makes it), wp_update_post() for one here, slashed, with both
	 * date columns and edit_date.
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The attachment here, null for a new one.
	 * @return int|WP_Error
	 */
	public static function write_row( array $prepared, $live_id ) {
		$postarr = $prepared['postarr'];
		if ( null !== $live_id ) {
			unset( $postarr['file'], $postarr['guid'] );
			$postarr['ID'] = (int) $live_id;
			$id            = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$id = wp_insert_attachment( wp_slash( $postarr ), false, 0, true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! is_int( $id ) || $id < 1 ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', 'WordPress wrote no attachment (it gave no id). Nothing more of the step is written; ask the site administrator which plugin holds it, then plan again.' );
		}
		return $id;
	}

	/**
	 * Write one part: an own key through the metadata API with the right to
	 * the attachment (edit_post; upload_files for the keys that name files),
	 * asked right before the call; any other part as for a post.
	 *
	 * @param int        $live_id  Attachment.
	 * @param string     $name     The name of the part.
	 * @param array      $prepared target_of().
	 * @param array|null $pre      current() right before the call.
	 * @return true|WP_Error
	 */
	public static function write_part( $live_id, $name, array $prepared, $pre ) {
		$name = (string) $name;
		$key  = 0 === strpos( $name, 'meta:' ) ? substr( $name, 5 ) : null;
		if ( null === $key || ! in_array( $key, self::OWN_META, true ) ) {
			return AB_MCP_Sync_Post_Writer::write_part( $live_id, $name, $prepared, $pre );
		}
		if ( ! current_user_can( 'edit_post', (int) $live_id ) || ( in_array( $key, self::FILE_META, true ) && ! current_user_can( 'upload_files' ) ) ) {
			return new WP_Error( 'ab_mcp_sync_rights', sprintf( 'The meta %1$s of the attachment was not written: this account may not %2$s here. Connect with an account that may, or leave the object out of the plan.', $key, in_array( $key, self::FILE_META, true ) ? 'edit the attachment and upload files (edit_post, upload_files)' : 'edit the attachment (edit_post)' ) );
		}
		$values = isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array();
		$from   = is_array( $pre ) ? AB_MCP_Sync_Post_Writer::meta_values( $pre, $key ) : array();
		return AB_MCP_Sync_Post_Writer::write_meta( $live_id, $key, $values, $from );
	}

	/* ------------------------------------------------------- as the post */

	/**
	 * @param string $name    Part.
	 * @param int    $live_id Attachment.
	 * @param array  $backup  current() of the backup.
	 * @param string $hash    The hash the part has to hold.
	 * @param int    $now     Time.
	 * @return array|WP_Error
	 */
	public static function restore_part( $name, $live_id, array $backup, $hash, $now ) {
		return AB_MCP_Sync_Post_Writer::restore_part( $name, $live_id, $backup, $hash, $now );
	}

	/**
	 * @param int|null $live_id Attachment, null for a new one.
	 * @param string   $subtype Post type.
	 * @return array
	 */
	public static function guard_of( $live_id, $subtype ) {
		unset( $subtype );
		return AB_MCP_Sync_Post_Writer::guard_of( $live_id, self::TYPE );
	}

	/**
	 * @param array $object The object of a journal.
	 * @return string
	 */
	public static function subtype_of( array $object ) {
		unset( $object );
		return self::TYPE;
	}

	/**
	 * @param array $prepared target_of().
	 * @return string
	 */
	public static function title_of( array $prepared ) {
		return AB_MCP_Sync_Post_Writer::title_of( $prepared );
	}

	/**
	 * @param int $live_id Attachment.
	 * @return array|null|WP_Error
	 */
	public static function current( $live_id ) {
		return AB_MCP_Sync_Post_Writer::current( $live_id );
	}

	/**
	 * @param array|null $current current().
	 * @param string     $part    Part.
	 * @return string|null
	 */
	public static function part_hash( $current, $part ) {
		return AB_MCP_Sync_Post_Writer::part_hash( $current, $part );
	}

	/**
	 * @param array $columns The written columns of a row.
	 * @return string
	 */
	public static function row_hash( array $columns ) {
		return AB_MCP_Sync_Post_Writer::row_hash( $columns );
	}

	/**
	 * The canonical hash of an attachment here, read as the inventory reads
	 * it: its row, meta, terms and every file of its list with its SHA-256.
	 *
	 * @param int   $live_id Attachment.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return string|null
	 */
	public static function live_hash( $live_id, array $source ) {
		$read = self::live_read( (int) $live_id, $source );
		return is_array( $read ) && isset( $read['hash'] ) && ! empty( $read['complete'] ) ? (string) $read['hash'] : null;
	}

	/**
	 * @param int $live_id Attachment.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		return AB_MCP_Sync_Post_Writer::post_write( $live_id );
	}

	/**
	 * @param string     $part    Part.
	 * @param array|null $backup  current() of the backup.
	 * @param array|null $now     current() now.
	 * @param int|null   $live_id Attachment.
	 * @return string
	 */
	public static function before_value( $part, $backup, $now, $live_id ) {
		return AB_MCP_Sync_Post_Writer::before_value( $part, $backup, $now, $live_id );
	}

	/**
	 * The canonical form of an attachment here, read as the inventory reads it.
	 *
	 * @param int   $live_id Attachment.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return array|null
	 */
	private static function live_read( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( self::TYPE );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => self::TYPE,
				'ref'    => (string) (int) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) ? $read : null;
	}
}
