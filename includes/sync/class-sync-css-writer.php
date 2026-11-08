<?php
/**
 * The write side of the kind custom_css (contract A of the plan of
 * 01.11.2026; Z8 branch 11, decisions 17, 23 and 25): the Additional CSS of
 * the active stylesheet, named by the stylesheet (ref_of()). The same methods
 * as the other kinds (AB_MCP_Sync_Engine::WRITERS).
 *
 * ONLY WHAT IS THERE IS CHANGED (decision 25): this site links a custom_css
 * post of the stylesheet in its theme mods (custom_css_post_id), the post is
 * not in the trash, the stylesheet is the active theme here, in the version
 * the staging site has. Then wp_update_custom_css_post() writes exactly that
 * post and leaves custom_css_post_id as it is. Creating Additional CSS is
 * locked (custom_css:create, Z8d: it would set custom_css_post_id as well,
 * and no way back through the trash undoes it, finding R4-2); so is CSS
 * with the source of a preprocessor or a status the core does not give it
 * (custom_css:field_rule, Z8d).
 *
 * THE CHECKS (decision 17): edit_css, as the Customizer asks it for the
 * setting (WP_Customize_Custom_CSS_Setting, capability edit_css); the CSS
 * through the rule of WP_Customize_Custom_CSS_Setting::validate() (no
 * closing tag of STYLE, and no end in a part of one; custom_css:markup), and
 * through the filters the core saves it with (update_custom_css_data,
 * sanitize_post() of wp_update_post()), without writing.
 *
 * THE WAY BACK (decision 23): the content of the post, where it holds what
 * the step read back, through wp_update_post() with the backed-up columns,
 * after the same checks as forward (edit_css, the theme, the rule of the
 * Customizer for the backed-up CSS).
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-shared-row.php';
require_once __DIR__ . '/class-sync-refs.php';
require_once __DIR__ . '/class-sync-db-kinds.php';
require_once __DIR__ . '/class-sync-post-writer.php';

/**
 * Class AB_MCP_Sync_Css_Writer
 */
final class AB_MCP_Sync_Css_Writer {

	/** The one part of a step, by its way: the row of the post through wp_update_post(), refusable (decision 19). */
	const WAY_ROW = 'refusable';

	/** The rules a value before is composed by: the same as the kind post's. */
	const VALUE_RULES = AB_MCP_Sync_Post_Writer::VALUE_RULES;

	/** The order of the parts on the way back: the row only. */
	const PART_ORDER = array( 'row' );

	/** The columns of the CSS post a step compares and writes. */
	const COLUMNS = array( 'post_id', 'post_type', 'post_name', 'post_title', 'post_status', 'post_content', 'post_content_filtered' );

	/**
	 * The object a ref names: the stylesheet, null for none (a ref
	 * stylesheet@id names a CSS post this site does not link: not written).
	 *
	 * @param mixed $raw Ref.
	 * @return string|null
	 */
	public static function ref_of( $raw ) {
		$ref = is_scalar( $raw ) ? (string) $raw : '';
		return '' === $ref ? null : $ref;
	}

	/* ------------------------------------------------------------ prepare */

	/**
	 * What a step writes for the CSS of one stylesheet: the CSS in this
	 * site's addresses and ids, the columns wp_update_custom_css_post()
	 * writes, the target form and its hash.
	 *
	 * @param array $line The line of the package (content, form, identity).
	 * @param array $item The item of the plan (op, live_ref).
	 * @param array $ctx  map, addresses, routes; pending (the check only).
	 * @return array|WP_Error
	 */
	public static function target_of( array $line, array $item, array $ctx ) {
		$stylesheet = isset( $line['identity']['stylesheet'] ) && is_string( $line['identity']['stylesheet'] ) ? $line['identity']['stylesheet'] : '';
		if ( 'update' !== $item['op'] || (string) $item['live_ref'] !== $stylesheet ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:create', sprintf( 'the CSS of %s is not linked to its stylesheet on this site', $stylesheet ) );
		}
		$held = AB_MCP_Sync_Post_Writer::line_lock( $line );
		if ( null !== $held ) {
			return $held;
		}
		if ( ! isset( $line['content']['row'], $line['form'] ) || ! is_array( $line['form'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', sprintf( 'the package carries no content of the CSS of %s', $stylesheet ) );
		}
		$row = array();
		foreach ( array( 'post_content', 'post_name', 'post_status', 'preprocessed', 'theme_version' ) as $column ) {
			if ( ! isset( $line['content']['row'][ $column ] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', sprintf( 'the package names no column %1$s of the CSS of %2$s', $column, $stylesheet ) );
			}
			$row[ $column ] = AB_MCP_Sync_Post_Writer::bytes( $line['content']['row'][ $column ] );
		}
		if ( '' !== $row['preprocessed'] || 'publish' !== $row['post_status'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:field_rule', '' !== $row['preprocessed'] ? 'the CSS of the staging site carries the source of a preprocessor (post_content_filtered)' : sprintf( 'the CSS post of the staging site has the status %s', $row['post_status'] ) );
		}
		$plan    = AB_MCP_Sync_Refs::plan( $ctx['map'], $ctx['addresses'], isset( $ctx['routes'] ) ? $ctx['routes'] : null );
		$changed = false;
		$css     = AB_MCP_Sync_Refs::rewrite_text( $row['post_content'], $plan, array(), $changed, false, true );
		$missing = AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], isset( $ctx['pending'] ) && is_array( $ctx['pending'] ) ? $ctx['pending'] : array() );
		if ( array() !== $missing ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:reference_unmapped', AB_MCP_Sync_Post_Writer::missing_text( $missing ) );
		}
		$target = AB_MCP_Sync_Canon::mapped( $line['form'], $ctx['map'] );
		return array(
			'type'        => 'custom_css',
			'stylesheet'  => $stylesheet,
			'version'     => $row['theme_version'],
			'status'      => null,
			'css'         => $css,
			// As wp_update_custom_css_post() writes them (wp-includes/theme.php).
			'columns'     => array(
				'post_type'             => 'custom_css',
				'post_name'             => sanitize_title( $stylesheet ),
				'post_title'            => $stylesheet,
				'post_status'           => 'publish',
				'post_content'          => $css,
				'post_content_filtered' => '',
			),
			'target_form' => $target,
			'target_hash' => AB_MCP_Sync_Canon::hash( $target ),
			'identity'    => $line['identity'],
			'as_draft'    => false,
		);
	}

	/**
	 * The checks before the part (decision 17, step 4): edit_css; the CSS
	 * post linked here, not in the trash; the stylesheet the active theme in
	 * the version of the staging site; the rule of the Customizer and the
	 * filters the core saves the CSS with.
	 *
	 * @param array       $prepared target_of().
	 * @param string|null $live_id  The stylesheet.
	 * @param int         $now      Time (not used).
	 * @return true|WP_Error
	 */
	public static function precheck( array $prepared, $live_id, $now ) {
		unset( $now );
		$stylesheet = (string) $prepared['stylesheet'];
		if ( ! current_user_can( 'edit_css' ) ) {
			return AB_MCP_Sync_Post_Writer::rights( sprintf( 'change the Additional CSS of %s (edit_css)', $stylesheet ) );
		}
		$refusal = self::theme_refusal( $stylesheet, (string) $prepared['version'] );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$current = self::current( null === $live_id ? $stylesheet : (string) $live_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current || 'trash' === $current['row']['post_status'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:create', null === $current ? sprintf( 'this site links no CSS post to %s (custom_css_post_id)', $stylesheet ) : sprintf( 'the CSS post %s of this site lies in the trash', $current['row']['post_id'] ) );
		}
		return self::css_refusal( (string) $prepared['css'], $stylesheet, (int) $current['row']['post_id'] );
	}

	/**
	 * Why the stylesheet is not written here: not the active theme, or the
	 * active one in another version than on the staging site.
	 *
	 * @param string $stylesheet The stylesheet.
	 * @param string $version    Its version on the staging site.
	 * @return WP_Error|null
	 */
	private static function theme_refusal( $stylesheet, $version ) {
		$active = get_stylesheet();
		if ( $active !== $stylesheet ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:theme_mismatch', sprintf( 'the CSS is of the theme %1$s, and the active theme of this site is %2$s', $stylesheet, $active ) );
		}
		// The version of the theme from its files ('' where it is not there:
		// WP_Theme::get() answers false then).
		$here = (string) wp_get_theme( $stylesheet )->get( 'Version' );
		if ( $here !== $version ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:theme_mismatch', sprintf( 'the theme %1$s is in the version %2$s on this site and %3$s on the staging site', $stylesheet, '' === $here ? '(none)' : $here, '' === $version ? '(none)' : $version ) );
		}
		return null;
	}

	/**
	 * The CSS through the rule of the Customizer and through what the core
	 * saves it with, without writing: null where it is stored as it is.
	 *
	 * @param string $css        The CSS.
	 * @param string $stylesheet The stylesheet.
	 * @param int    $post_id    The CSS post here.
	 * @return true|WP_Error
	 */
	private static function css_refusal( $css, $stylesheet, $post_id ) {
		if ( AB_MCP_Sync_Post_Writer::css_markup( $css ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:markup', 'the CSS holds a closing tag of STYLE, or ends in a part of one (WP_Customize_Custom_CSS_Setting::validate())' );
		}
		$args = array(
			'preprocessed' => '',
			'stylesheet'   => $stylesheet,
			'css'          => $css,
		);
		$data = apply_filters( 'update_custom_css_data', array( 'css' => $css, 'preprocessed' => '' ), $args );
		if ( ! is_array( $data ) || ! isset( $data['css'], $data['preprocessed'] ) || (string) $data['css'] !== $css || '' !== (string) $data['preprocessed'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:markup', 'a filter of this site changes the CSS as it is saved (update_custom_css_data)' );
		}
		$slashed   = wp_slash( array( 'post_content' => $css ) );
		$sanitized = (array) sanitize_post( $slashed + array( 'post_type' => 'custom_css', 'ID' => (int) $post_id ), 'db' );
		if ( ! isset( $sanitized['post_content'] ) || (string) $sanitized['post_content'] !== (string) $slashed['post_content'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:markup', 'the sanitizing WordPress runs on a save changes the CSS (kses without unfiltered_html)' );
		}
		return true;
	}

	/**
	 * Whether the CSS here is one this version writes as it is: its canonical
	 * form carries no lock.
	 *
	 * @param string $live_id The stylesheet.
	 * @param array  $source  AB_MCP_Sync_Inventory::source().
	 * @return true|WP_Error
	 */
	public static function live_rule( $live_id, array $source ) {
		$lock = AB_MCP_Sync_Post_Writer::read_lock( self::live_read( (string) $live_id, $source ), sprintf( 'the CSS of %s on this site', (string) $live_id ) );
		return null === $lock ? true : $lock;
	}

	/**
	 * The parts of the journal: the row of the CSS post.
	 *
	 * @param array      $prepared target_of().
	 * @param array|null $current  current().
	 * @return array[]
	 */
	public static function journal_parts( array $prepared, $current ) {
		return array(
			array(
				'part'   => 'row',
				'way'    => self::WAY_ROW,
				'before' => is_array( $current ) ? self::row_hash( $current['row'] ) : null,
				'target' => self::row_hash( array( 'post_id' => is_array( $current ) ? $current['row']['post_id'] : '' ) + $prepared['columns'] ),
			),
		);
	}

	/* ---------------------------------------------------------- reading */

	/**
	 * The CSS here: the custom_css post the theme mods of the stylesheet link
	 * (custom_css_post_id), from its row, and that link as a part of the
	 * state; null where none is linked; a WP_Error where a row could not be
	 * read.
	 *
	 * @param string $live_id The stylesheet.
	 * @return array{row:array,meta:array,terms:array,parts:array}|null|WP_Error
	 */
	public static function current( $live_id ) {
		$stylesheet = (string) $live_id;
		$mods       = AB_MCP_Sync_Shared_Row::read( 'theme_mods_' . $stylesheet );
		if ( false === $mods ) {
			return null;
		}
		if ( ! is_array( $mods ) || ! $mods['ok'] || ! is_array( $mods['value'] ) ) {
			return self::unread( $stylesheet );
		}
		$link = array_key_exists( 'custom_css_post_id', $mods['value'] ) ? $mods['value']['custom_css_post_id'] : null;
		$id   = AB_MCP_Sync_Canon::id_spelling( $link );
		if ( null === $id || $id < 1 ) {
			return null;
		}
		$row = AB_MCP_Sync_Rows::post( (int) $id );
		if ( false === $row || ( is_array( $row ) && 'custom_css' !== $row['post_type'] ) ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			return self::unread( $stylesheet );
		}
		$columns = array( 'post_id' => (string) (int) $id );
		foreach ( self::COLUMNS as $column ) {
			if ( 'post_id' !== $column ) {
				$columns[ $column ] = (string) $row[ $column ];
			}
		}
		return array(
			'row'   => $columns,
			'meta'  => array(),
			'terms' => array(),
			'parts' => array( 'mods:custom_css_post_id' => AB_MCP_Sync_Shared_Row::field( $link ) ),
		);
	}

	/**
	 * @param string $stylesheet The stylesheet.
	 * @return WP_Error
	 */
	private static function unread( $stylesheet ) {
		return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The CSS of %s could not be read (its theme mods or its post), so nothing is written for it. Try again; if it keeps failing, the database error log names the cause.', $stylesheet ) );
	}

	/**
	 * @param array|null $current current(), null for none.
	 * @param string     $part    The name of the part.
	 * @return string|null
	 */
	public static function part_hash( $current, $part ) {
		$part = (string) $part;
		if ( 'row' === $part ) {
			return is_array( $current ) ? self::row_hash( $current['row'] ) : null;
		}
		if ( ! is_array( $current ) ) {
			return null;
		}
		return AB_MCP_Sync_Shared_Row::hash( isset( $current['parts'][ $part ] ) ? $current['parts'][ $part ] : null );
	}

	/**
	 * @param array $columns COLUMNS.
	 * @return string
	 */
	public static function row_hash( array $columns ) {
		$row = array();
		foreach ( self::COLUMNS as $column ) {
			$row[ $column ] = isset( $columns[ $column ] ) ? (string) $columns[ $column ] : '';
		}
		return AB_MCP_Sync_Canon::hash( $row );
	}

	/**
	 * @param string $live_id The stylesheet.
	 * @param array  $source  AB_MCP_Sync_Inventory::source().
	 * @return array|null
	 */
	private static function live_read( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( 'custom_css' );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => 'custom_css',
				'ref'    => (string) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) ? $read : null;
	}

	/**
	 * @param string $live_id The stylesheet.
	 * @param array  $source  AB_MCP_Sync_Inventory::source().
	 * @return string|null
	 */
	public static function live_hash( $live_id, array $source ) {
		$read = self::live_read( (string) $live_id, $source );
		return is_array( $read ) && isset( $read['hash'] ) && ! empty( $read['complete'] ) ? (string) $read['hash'] : null;
	}

	/* ----------------------------------------------------------- writing */

	/**
	 * @param array       $prepared target_of().
	 * @param string|null $live_id  The stylesheet.
	 * @param array|null  $current  current().
	 * @return array<string,string>
	 */
	public static function with_row( array $prepared, $live_id, $current = null ) {
		unset( $prepared, $live_id, $current );
		return array();
	}

	/**
	 * @param array  $prepared target_of().
	 * @param string $name     Part.
	 * @return bool
	 */
	public static function through_row( array $prepared, $name ) {
		unset( $prepared, $name );
		return false;
	}

	/**
	 * Write the CSS: wp_update_custom_css_post() for the stylesheet, which
	 * changes the linked post and not the link; edit_css asked right before.
	 *
	 * @param array       $prepared target_of().
	 * @param string|null $live_id  The stylesheet.
	 * @return string|WP_Error The stylesheet.
	 */
	public static function write_row( array $prepared, $live_id, $pre = null ) {
		$stylesheet = (string) $prepared['stylesheet'];
		if ( null === $live_id || (string) $live_id !== $stylesheet ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:create', sprintf( 'the CSS of %s is not linked to its stylesheet on this site', $stylesheet ) );
		}
		if ( null === $pre ) {
			return AB_MCP_Sync_Post_Writer::locked( 'custom_css:create', sprintf( 'this site links no CSS post to %s (custom_css_post_id)', $stylesheet ) );
		}
		// The right, the last read of the CSS against the state the engine
		// read, the call (AB_MCP_Sync_Write; Codex round 3 of branch 11, A6).
		// wp_update_custom_css_post() finds the post by the link in the theme
		// mods, read through the cache of the options: emptied first, so it
		// finds the post the step read (Codex round 1 of branch 11, A1),
		// never makes a second one from a stale link; before the last read,
		// and the post of the call is the one the comparison read (Codex
		// round 4, item 4: no read between the comparison and the call).
		return AB_MCP_Sync_Write::forward(
			self::class,
			$stylesheet,
			$pre,
			static fn() => current_user_can( 'edit_css' ) ? true : AB_MCP_Sync_Post_Writer::rights( sprintf( 'change the Additional CSS of %s (edit_css)', $stylesheet ) ),
			static fn() => self::css_call( $prepared, $stylesheet, (int) $pre['row']['post_id'] ),
			static function () use ( $stylesheet ) {
				AB_MCP_Sync_Shared_Row::forget( 'stylesheet' );
				AB_MCP_Sync_Shared_Row::forget( 'theme_mods_' . $stylesheet );
				return null;
			}
		);
	}

	/**
	 * The call of the core for the CSS: wp_update_custom_css_post() with no
	 * revision of its post deleted.
	 *
	 * @param array  $prepared   target_of().
	 * @param string $stylesheet The stylesheet.
	 * @param int    $post_id    The linked post, as the last read read it.
	 * @return string|WP_Error The stylesheet.
	 */
	private static function css_call( array $prepared, $stylesheet, $post_id ) {
		$done = AB_MCP_Sync_Post_Writer::keeping_revisions(
			(int) $post_id,
			static function () use ( $prepared, $stylesheet ) {
				return wp_update_custom_css_post(
					(string) $prepared['css'],
					array(
						'preprocessed' => '',
						'stylesheet'   => $stylesheet,
					)
				);
			}
		);
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return $stylesheet;
	}

	/**
	 * The CSS has no part of its own call besides the row.
	 *
	 * @param string     $live_id  The stylesheet.
	 * @param string     $name     Part.
	 * @param array      $prepared target_of().
	 * @param array|null $pre      current().
	 * @return WP_Error
	 */
	public static function write_part( $live_id, $name, array $prepared, $pre ) {
		unset( $live_id, $prepared, $pre );
		return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The part %s is of a name the kind custom_css does not write. Check the object in wp-admin.', (string) $name ) );
	}

	/**
	 * After the writes and the way back (decision 33): the caches of the CSS
	 * post and the page cache of the whole site (every page shows the CSS).
	 *
	 * @param string $live_id The stylesheet.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		$current = self::current( (string) $live_id );
		$done    = array();
		if ( is_array( $current ) ) {
			clean_post_cache( (int) $current['row']['post_id'] );
			$done[] = 'post';
		}
		if ( class_exists( 'AB_MCP_Page_Cache' ) && is_array( AB_MCP_Page_Cache::purge_site() ) ) {
			$done[] = 'page_cache_site';
		}
		return $done;
	}

	/**
	 * What the takeover lock covers: the CSS post linked here.
	 *
	 * @param string|null $live_id The stylesheet.
	 * @param string      $subtype custom_css.
	 * @return array{posts:array,taxonomies:array,terms:array,options:array}
	 */
	public static function guard_of( $live_id, $subtype ) {
		unset( $subtype );
		$current = null === $live_id ? null : self::current( (string) $live_id );
		return array(
			'posts'      => is_array( $current ) ? array( (int) $current['row']['post_id'] => 'custom_css' ) : array(),
			'taxonomies' => array(),
			'terms'      => array(),
			'options'    => array(),
		);
	}

	/**
	 * @param array $object The object of a journal.
	 * @return string custom_css.
	 */
	public static function subtype_of( array $object ) {
		unset( $object );
		return 'custom_css';
	}

	/**
	 * @param array $prepared target_of().
	 * @return string
	 */
	public static function title_of( array $prepared ) {
		return (string) $prepared['stylesheet'];
	}

	/* ---------------------------------------------------------- way back */

	/**
	 * Whether this account may put the CSS back (as forward): edit_css, the
	 * stylesheet the active theme, the backed-up CSS keeping the rule of the
	 * Customizer.
	 *
	 * @param string $part    Part.
	 * @param string $live_id The stylesheet.
	 * @param array  $backup  current() of the backup.
	 * @param array  $now     current() now.
	 * @return true|string
	 */
	public static function restore_right( $part, $live_id, array $backup, array $now ) {
		unset( $part, $now );
		if ( ! current_user_can( 'edit_css' ) ) {
			return sprintf( 'change the Additional CSS of %s (edit_css)', (string) $live_id );
		}
		if ( get_stylesheet() !== (string) $live_id ) {
			return sprintf( 'write the CSS of %s, which is not the active theme of this site now', (string) $live_id );
		}
		if ( AB_MCP_Sync_Post_Writer::css_markup( (string) $backup['row']['post_content'] ) ) {
			return 'store the backed-up CSS, which holds a closing tag of STYLE (the rule of the Customizer)';
		}
		return true;
	}

	/**
	 * Put the row back (decision 23): where it holds what the step read back,
	 * through wp_update_post() with the backed-up columns.
	 *
	 * @param string $name    Part.
	 * @param string $live_id The stylesheet.
	 * @param array  $backup  current() of the backup.
	 * @param string $hash    The hash the row has to hold.
	 * @param int    $now     Time.
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_part( $name, $live_id, array $backup, $hash, $now ) {
		unset( $now );
		if ( 'row' !== (string) $name ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'A part %s of the CSS is of a name this version does not know: left as it is.', (string) $name ),
			);
		}
		$current = self::current( (string) $live_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current || $current['row']['post_id'] !== $backup['row']['post_id'] ) {
			return array(
				'state' => 'left',
				'note'  => 'This site no longer links the CSS post of the backup to the stylesheet, so the CSS is left as it is.',
			);
		}
		$before = self::row_hash( $backup['row'] );
		$now    = self::row_hash( $current['row'] );
		if ( hash_equals( $before, $now ) ) {
			return array( 'state' => 'unchanged' );
		}
		if ( ! hash_equals( (string) $hash, $now ) ) {
			return array(
				'state' => 'left',
				'note'  => 'The CSS was changed since the step wrote it, so it is left as it is.',
			);
		}
		// The right (A5), the last read against what the step wrote, the call
		// (AB_MCP_Sync_Write; Codex rounds 2 and 3 of branch 11, A6).
		$id = AB_MCP_Sync_Write::back(
			static fn() => current_user_can( 'edit_css' ) ? true : sprintf( 'change the Additional CSS of %s (edit_css)', (string) $live_id ),
			static fn() => self::current( (string) $live_id ),
			static fn( $again ) => is_array( $again ) && $again['row']['post_id'] === $backup['row']['post_id'] && hash_equals( (string) $hash, self::row_hash( $again['row'] ) ),
			static function () use ( $backup ) {
				return AB_MCP_Sync_Post_Writer::keeping_revisions(
					(int) $backup['row']['post_id'],
					static function () use ( $backup ) {
						return wp_update_post(
							wp_slash(
								array(
									'ID'                    => (int) $backup['row']['post_id'],
									'post_content'          => (string) $backup['row']['post_content'],
									'post_content_filtered' => (string) $backup['row']['post_content_filtered'],
									'post_title'            => (string) $backup['row']['post_title'],
									'post_name'             => (string) $backup['row']['post_name'],
									'post_status'           => (string) $backup['row']['post_status'],
								)
							),
							true
						);
					}
				);
			},
			'The CSS changed as it was put back (a hook of this site), so it is left as it is.'
		);
		if ( is_wp_error( $id ) || is_array( $id ) ) {
			return $id;
		}
		$after = self::current( (string) $live_id );
		return array( 'state' => is_array( $after ) && hash_equals( $before, self::row_hash( $after['row'] ) ) ? 'restored' : 'unclear' );
	}

	/**
	 * What the CSS held before the step, never a credential.
	 *
	 * @param string     $part    Part.
	 * @param array|null $backup  current() of the backup.
	 * @param array|null $now     current() now.
	 * @param mixed      $live_id The stylesheet.
	 * @return string
	 */
	public static function before_value( $part, $backup, $now, $live_id ) {
		unset( $part, $now, $live_id );
		if ( ! is_array( $backup ) ) {
			return 'none: the CSS was not on this site before the run.';
		}
		$css = (string) $backup['row']['post_content'];
		return AB_MCP_Sync_Post_Writer::cut( sprintf( 'post_content: %1$d bytes, %2$s', strlen( $css ), AB_MCP_Sync_Post_Writer::shown_value( 'post_content', $css ) ), AB_MCP_Sync_Post_Writer::PART_CHARS );
	}
}
