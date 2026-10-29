<?php
/**
 * What page builders build from a page's stored data and keep: rendered HTML,
 * CSS, asset lists. After a write that goes around the builder's save, and
 * after every restore of an undo point, they are stale until emptied.
 *
 * Each entry names where it was read; the keys are deleted by name, the way
 * the builders' own clean-ups do it. Deleting a key a post does not have
 * costs nothing, so the meta caches are emptied for every post; the calls
 * into a builder's own code only where that builder wrote the page.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Caches
 */
class AB_MCP_Builder_Caches {

	/**
	 * Post meta made from the content and rebuilt on the next view.
	 * - Spectra: _uag_page_assets, the page's CSS and asset list (measured,
	 *   ~70 KB, classes/class-uagb-post-assets.php:829); with its file
	 *   generation on, _uag_css_file_name and _uag_js_file_name, the files
	 *   written from it. Spectra's own save deletes all three (measured,
	 *   bloecke/README.md of the measurement, e); class-uagb-helper.php:1570-1572).
	 * Elementor's keys are read from its constants (elementor_keys()).
	 */
	const META = self::SPECTRA_META;

	/** Spectra's keys: a post that had one is a page Spectra built assets for. */
	const SPECTRA_META = array( '_uag_page_assets', '_uag_css_file_name', '_uag_js_file_name' );

	/**
	 * Spectra's asset version, the ?ver= of the CSS and JS files it writes
	 * for a page. Its save sets it to the time (class-uagb-helper.php:1575):
	 * a file written anew under the old address would be served from browser
	 * and CDN caches with the CSS of before.
	 */
	const SPECTRA_VERSION = '__uagb_asset_version';

	/**
	 * Empty the builder caches of one post.
	 *
	 * @param int    $post_id Post.
	 * @param string $builder Adapter id that wrote it ('' when unknown: every
	 *                        meta cache is emptied, no builder code is called).
	 * @return string[] Names of the caches emptied.
	 */
	public static function clear( $post_id, $builder = '' ) {
		$post_id = (int) $post_id;
		$done    = array();
		if ( $post_id <= 0 ) {
			return $done;
		}
		$spectra = false;
		foreach ( self::SPECTRA_META as $key ) {
			if ( array() !== (array) get_post_meta( $post_id, $key ) ) {
				$spectra = true;
				break;
			}
		}
		foreach ( array_merge( self::META, self::elementor_keys() ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
		$done[] = 'post_meta';

		// As Spectra's save does: a new asset version gives the files new
		// addresses. Only where Spectra keeps one, and only for a page it
		// had built assets for (one site-wide value; every Spectra page's
		// files get a new address, as after any save in Spectra).
		if ( $spectra && false !== get_option( self::SPECTRA_VERSION, false ) ) {
			update_option( self::SPECTRA_VERSION, time() );
			$done[] = 'spectra';
		}

		// GenerateBlocks: the option marks which pages have a current CSS
		// file; a page taken out of it gets its file written again on the
		// next view (measured: what save_post does, includes/class-enqueue-css.php:443-446).
		$gb = get_option( 'generateblocks_dynamic_css_posts', null );
		if ( is_array( $gb ) && array_key_exists( $post_id, $gb ) ) {
			unset( $gb[ $post_id ] );
			update_option( 'generateblocks_dynamic_css_posts', $gb );
			$done[] = 'generateblocks';
		}

		try {
			if ( 'elementor' === $builder ) {
				// Elementor 4 atomic styles are files made from the class
				// styles in the JSON; Elementor's own save empties them
				// through this action.
				do_action( 'elementor/atomic-widgets/styles/clear', array( self::elementor_constant( 'Elementor\\Modules\\AtomicWidgets\\Styles\\Atomic_Widget_Styles::STYLES_KEY', 'local' ), $post_id ) );
				$done[] = 'elementor';
			}
			if ( 'beaver' === $builder && class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'delete_all_asset_cache' ) ) {
				FLBuilderModel::delete_all_asset_cache( $post_id );
				$done[] = 'beaver';
			}
		} catch ( \Throwable $e ) {
			// A builder that throws while emptying its cache leaves a stale
			// cache, not a broken write; the write itself is done.
			unset( $e );
		}
		return $done;
	}

	/**
	 * After a writer's write: the builder caches, the marker that tells an
	 * open Elementor editor its document was changed from outside, and — for
	 * a write to the live page — the page caches of caching plugins.
	 *
	 * @param int    $post_id Post.
	 * @param string $builder Adapter id.
	 * @param bool   $live    Whether the live page changed (false for a write into an autosave).
	 * @return array { builder_caches: string[], page_cache_purged: string[], page_cache_failed?: string[] }
	 */
	public static function after_write( $post_id, $builder, $live = true ) {
		$out = array( 'builder_caches' => self::clear( $post_id, $builder ) );
		if ( 'elementor' === $builder ) {
			self::mark_mutation( $post_id );
		}
		$out['page_cache_purged'] = array();
		if ( $live && class_exists( 'AB_MCP_Page_Cache' ) ) {
			$purge                    = AB_MCP_Page_Cache::purge_posts( array( (int) $post_id ) );
			$out['page_cache_purged'] = $purge['purged'];
			if ( ! empty( $purge['failed'] ) ) {
				$out['page_cache_failed'] = $purge['failed'];
			}
		}
		return $out;
	}

	/**
	 * Tell Elementor's editor that the document was changed by an MCP client
	 * (Elementor 4.3: Editor_Sync_State::set_mcp_mutation(), called by its
	 * own abilities after each change). An editor open on the page learns it
	 * through its heartbeat instead of overwriting the change unseen.
	 *
	 * @param int $post_id Post.
	 * @return bool Whether Elementor took the marker.
	 */
	public static function mark_mutation( $post_id ) {
		$class = 'Elementor\\Modules\\Mcp\\Utils\\Editor_Sync_State';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'set_mcp_mutation' ) ) {
			return false;
		}
		try {
			call_user_func( array( $class, 'set_mcp_mutation' ), (int) $post_id );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Elementor's per-post caches made from _elementor_data: its CSS, the
	 * rendered HTML (element cache, 24 h unless set otherwise), the Markdown
	 * it serves (as in wp_search_replace) and the list of styles and scripts
	 * the page needs (core/base/elements-iteration-actions/assets.php:13 in
	 * 4.3.3, built again at the next view when missing, includes/frontend.php:
	 * 707-720; Elementor's own cache clearing deletes it with the CSS and the
	 * element cache, core/files/manager.php:148-150).
	 *
	 * @return string[]
	 */
	public static function elementor_keys() {
		return array(
			'_elementor_css',
			self::elementor_constant( 'Elementor\\Core\\Base\\Document::CACHE_META_KEY', '_elementor_element_cache' ),
			self::elementor_constant( 'Elementor\\Modules\\MarkdownRender\\Module::CACHE_META_KEY', '_elementor_markdown_cache' ),
			self::elementor_constant( 'Elementor\\Core\\Base\\Elements_Iteration_Actions\\Assets::ASSETS_META_KEY', '_elementor_page_assets' ),
		);
	}

	/**
	 * Elementor's index of the global classes a page uses, for the live page
	 * and for the preview of its draft (modules/global-classes/global-classes-
	 * relations.php:18-22 in 4.3.3). Elementor writes it on the page itself
	 * at every save of the page or of its draft (on_document_save(), :245-268)
	 * and puts it right when it reads it (get_styles_by_post(), :85-124): it
	 * says nothing about a change that the page's data does not say. Not
	 * emptied by clear(), only left out where copies are compared.
	 *
	 * @return string[]
	 */
	public static function elementor_index_keys() {
		$class = 'Elementor\\Modules\\GlobalClasses\\Global_Classes_Relations';
		return array(
			self::elementor_constant( $class . '::META_KEY_FRONTEND', '_elementor_used_global_class' ),
			self::elementor_constant( $class . '::META_KEY_PREVIEW', '_elementor_used_global_class_preview' ),
			self::elementor_constant( $class . '::META_KEY_USAGE_INDEXED_FRONTEND', '_elementor_global_class_usage_indexed' ),
			self::elementor_constant( $class . '::META_KEY_USAGE_INDEXED_PREVIEW', '_elementor_global_class_usage_indexed_preview' ),
		);
	}

	/**
	 * A key from Elementor's constant while Elementor is loaded, else its
	 * value in Elementor 4.3.3.
	 *
	 * @param string $constant Fully qualified class constant.
	 * @param string $fallback Value in Elementor 4.3.3.
	 * @return string
	 */
	private static function elementor_constant( $constant, $fallback ) {
		if ( defined( $constant ) ) {
			$key = constant( $constant );
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}
		return $fallback;
	}
}
