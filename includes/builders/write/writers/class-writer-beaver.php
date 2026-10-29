<?php
/**
 * Writer for Beaver Builder (storage B): the page is the layout in
 * _fl_builder_data, the editor's unpublished draft is _fl_builder_draft, and
 * post_content is a text copy the site does not show while Beaver is active.
 *
 * How it saves (plan review T5): through Beaver's own model, the core of
 * FLBuilderModel::save_settings() without the parts that need the editor
 * [SVN beaver-builder-lite-version@2.11.0.6 classes/class-fl-builder-model.php:
 * 5196-5284]:
 * - FLBuilderModel::set_post_id() first (:449), so every call works on this
 *   post and not on whatever the request's globals point at; reset after;
 * - the node's settings with the module's defaults merged
 *   (get_node_settings_with_defaults_merged(), :1788 — what get_node() gives
 *   save_settings(), minus the fl_builder_node_settings filter, which would
 *   write resolved dynamic values into the stored settings), the new values
 *   set on a deep copy;
 * - for an account without unfiltered_html, FLBuilderModel::verify_settings()
 *   on the new values (:5330): where Beaver would silently keep the old
 *   settings, AlphaBridge refuses and says which field;
 * - process_node_settings() (:1823): the module's own update() — the photo
 *   module caches the attachment data and renews its crop there — and the
 *   sanitising of link fields;
 * - update_layout_data() for the published layout and, kept in step, the
 *   draft (:5960): Beaver cleans, slashes and stores it.
 * Left out: FLBuilderAJAXLayout::render() — it renders the whole layout for
 * the editor and needs its request — and save_layout(), which would publish
 * the draft. The published layout changes directly, as Beaver's own code does
 * outside the editor (:852-855).
 *
 * The draft: Beaver's editor works on _fl_builder_draft and publishes it as a
 * whole. A change to the live page is made in the draft too, field by field,
 * where the draft holds the same value as the live page; where it holds
 * another (an unpublished edit), the draft is left and the answer says that
 * publishing it would bring its version back. publish=false changes only the
 * draft — the live page stays as it is until someone publishes in Beaver.
 *
 * A photo (field photo, an attachment id) is set together with photo_src,
 * the address Beaver renders [modules/photo/photo.php:83-89, :556-560]: the
 * same image size as before where the new image has it, else the full size.
 *
 * post_content is Beaver's text copy, written by save_layout() from a render
 * of the layout. It is not rendered here; it stays as it was until the page
 * is next published in Beaver, and the answer says so.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-builder-settings-writer.php';

/**
 * Class AB_MCP_Builder_Writer_Beaver
 */
class AB_MCP_Builder_Writer_Beaver extends AB_MCP_Builder_Settings_Writer {

	/** The published layout. */
	const DATA = '_fl_builder_data';

	/** The editor's draft. */
	const DRAFT = '_fl_builder_draft';

	/**
	 * Whether this way of writing was checked on a real installation: it was.
	 * Built from Beaver Builder Lite 2.11.0.6's code and tested against a
	 * stand-in of FLBuilderModel (test/builder-writers-meta-test.php), then run
	 * against Beaver Builder Lite 2.11.0.6 itself on WordPress Playground
	 * (WordPress 7.1.2, PHP 8.3.33; Pro 4.7.0, bundled core 4.5.0; 02.10.2026;
	 * recipes beaver and beaver-entwurf of test/e2e/builders/): heading, text,
	 * link and photo show to a visitor with their new value and the draft
	 * follows; a draft with an unpublished edit is left, with the warning;
	 * publish=false changes only the draft; wp_search_replace over eight pages
	 * goes through this writer; every undo put the page back byte for byte.
	 * Not measured: an account without unfiltered_html (verify_settings()),
	 * opening the page in Beaver's editor afterwards, modules of Beaver
	 * Builder Pro and of other vendors, Beaver's asset cache on a real host.
	 */
	const MEASURED = true;

	/** FLBuilderModel methods the writer saves through (all in 2.11.0.6). */
	const NEEDS = array( 'set_post_id', 'reset_post_id', 'get_layout_data', 'update_layout_data', 'process_node_settings', 'get_node_settings_with_defaults_merged' );

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'beaver';
	}

	/**
	 * Beaver keeps a draft of its own: publish=false writes into it.
	 *
	 * @return bool
	 */
	public function supports_draft(): bool {
		return true;
	}

	/**
	 * See MEASURED.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function verified( $post ): bool {
		return self::MEASURED && parent::verified( $post );
	}

	/**
	 * Beaver's model has to be loaded: AlphaBridge saves through it, never
	 * past it.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function can_write( $post ) {
		if ( ! class_exists( 'FLBuilderModel', false ) ) {
			return new WP_Error( 'ab_mcp_builder_cannot_write', __( 'Beaver Builder\'s code is not loaded here, and AlphaBridge saves Beaver layouts only through it. Activate Beaver Builder, or change the page in its editor.', 'alphabridge-mcp' ) );
		}
		foreach ( self::NEEDS as $method ) {
			if ( ! method_exists( 'FLBuilderModel', $method ) ) {
				return new WP_Error(
					'ab_mcp_builder_cannot_write',
					/* translators: %s: method name */
					sprintf( __( 'This Beaver Builder has no FLBuilderModel::%s(), which AlphaBridge saves through (it is built for Beaver Builder 2.11). Update Beaver Builder, or change the page in its editor.', 'alphabridge-mcp' ), $method )
				);
			}
		}
		return true;
	}

	/**
	 * The stored settings of a node of the published layout.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $element Element.
	 * @return object|WP_Error
	 */
	protected function settings_of( $post, array $element ) {
		$node = self::node( get_post_meta( (int) $post->ID, self::DATA, true ), (string) $element['id'] );
		if ( null === $node ) {
			return self::gone( (string) $element['id'], 'settings' );
		}
		return $node->settings;
	}

	/**
	 * Matches in the stored published layout (serialized: strings stand there
	 * as they are).
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $search Text.
	 * @param bool    $ci     Case-insensitive.
	 * @return int
	 */
	protected function count_stored( $post, $search, $ci = false ) {
		$raw = AB_MCP_Undo_Builder::meta_rows( (int) $post->ID, self::DATA );
		return array() === $raw ? 0 : self::count_in( (string) $raw[0]['value'], (string) $search, $ci );
	}

	/**
	 * Which copies to write, the draft's state per field, the photo address,
	 * and Beaver's own check of the new values.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return array|WP_Error
	 */
	protected function prepare( $post, array $change, array $opts ) {
		$id      = (int) $post->ID;
		$publish = ! array_key_exists( 'publish', $opts ) || (bool) $opts['publish'];
		$drafts  = get_post_meta( $id, self::DRAFT, false );
		$draft   = array() !== $drafts ? reset( $drafts ) : null;
		$live    = get_post_meta( $id, self::DATA, true );
		$warn    = isset( $change['warnings'] ) ? (array) $change['warnings'] : array();
		$notes   = isset( $change['notes'] ) ? (array) $change['notes'] : array();

		if ( ! $publish ) {
			if ( 'text' === $change['mode'] ) {
				return new WP_Error( 'ab_mcp_builder_no_draft', __( 'Replacing a text changes the live page and keeps Beaver Builder\'s draft in step; publish=false is for changing one element (wp_update_builder_element). Leave publish out.', 'alphabridge-mcp' ) );
			}
			if ( ! is_array( $draft ) ) {
				return new WP_Error( 'ab_mcp_builder_no_draft', __( 'This page has no Beaver Builder draft yet (Beaver makes one when the page is first opened in its editor). Leave publish out to change the live page, or open the page in Beaver Builder once.', 'alphabridge-mcp' ) );
			}
			$change['status']    = 'draft';
			$change['draft_ids'] = self::node_ids( $draft );
			foreach ( $change['changes'] as $i => $c ) {
				$node = self::node( $draft, $c['element'] );
				$have = null === $node ? null : self::value_at( $node->settings, $c['data']['path'] );
				if ( null === $have || ! is_scalar( $have ) || is_bool( $have ) ) {
					return new WP_Error(
						'ab_mcp_builder_no_draft',
						/* translators: %s: element id */
						sprintf( __( 'Element %s is not in Beaver Builder\'s draft of this page (it was removed there and not published yet). Leave publish out to change the live page, or change the draft in Beaver Builder.', 'alphabridge-mcp' ), $c['element'] )
					);
				}
				// The draft's own value is what this change replaces there.
				$change['changes'][ $i ]['before']        = $have;
				$change['changes'][ $i ]['data']['draft'] = true;
				$change['changes'][ $i ]['data']['live']  = false;
			}
			$notes[] = __( 'Saved into Beaver Builder\'s draft only: the live page keeps its version until someone publishes the page in Beaver Builder.', 'alphabridge-mcp' );
		} else {
			$change['status']    = 'published';
			$change['draft_ids'] = is_array( $draft ) ? self::node_ids( $draft ) : array();
			foreach ( $change['changes'] as $i => $c ) {
				$change['changes'][ $i ]['data']['live']  = true;
				$change['changes'][ $i ]['data']['draft'] = false;
				if ( ! is_array( $draft ) ) {
					continue; // No draft: the editor starts from the live page.
				}
				$node = self::node( $draft, $c['element'] );
				$have = null === $node ? null : self::value_at( $node->settings, $c['data']['path'] );
				if ( null !== $have && is_scalar( $have ) && (string) $have === (string) $c['before'] ) {
					$change['changes'][ $i ]['data']['draft'] = true;
					continue;
				}
				$warn[] = sprintf(
					/* translators: 1: element id, 2: field name */
					__( 'Beaver Builder\'s unpublished draft holds another version of %1$s (%2$s), so it was left as it is: publishing that draft in Beaver Builder brings its version back. Change it there too, or discard the draft.', 'alphabridge-mcp' ),
					$c['element'],
					$c['field']
				);
			}
			if ( array() !== $change['changes'] ) {
				$notes[] = __( 'post_content keeps Beaver Builder\'s text copy of the page as it was; Beaver renews it the next time the page is published in its editor. The site does not show it while Beaver Builder is active.', 'alphabridge-mcp' );
			}
		}

		// A photo: its address, for the image size it showed.
		foreach ( $change['changes'] as $i => $c ) {
			if ( 'image' !== $c['kind'] || 'photo' !== $c['data']['path'] ) {
				continue;
			}
			$source = $change['changes'][ $i ]['data']['live'] ? $live : $draft;
			$node   = self::node( $source, $c['element'] );
			$change['changes'][ $i ]['data']['also'] = array( 'photo_src' => self::photo_src( null === $node ? null : $node->settings, (int) $c['after'] ) );
		}

		// Beaver refuses new values that kses would change for an account
		// without unfiltered_html, and keeps the old settings without a word
		// (save_settings(), class-fl-builder-model.php:5218-5225). Asked here,
		// before the preview, with Beaver's own check.
		if ( class_exists( 'FLBuilderModel', false ) && method_exists( 'FLBuilderModel', 'verify_settings' ) && ! self::unfiltered() ) {
			foreach ( self::by_element( $change['changes'] ) as $element_id => $list ) {
				$values = new stdClass();
				foreach ( $list as $c ) {
					if ( is_string( $c['after'] ) ) {
						$key            = str_replace( '.', '_', $c['field'] );
						$values->{$key} = $c['after'];
					}
				}
				$check = FLBuilderModel::verify_settings( $values );
				if ( true !== $check ) {
					$key = is_array( $check ) && isset( $check['key'] ) ? (string) $check['key'] : '?';
					return new WP_Error(
						'ab_mcp_builder_kses',
						/* translators: 1: element id, 2: field */
						sprintf( __( 'Beaver Builder does not store the new value of %1$s (%2$s) for an account without unfiltered_html: it holds markup Beaver filters out. Nothing was changed. Send it without that markup, or ask an administrator.', 'alphabridge-mcp' ), $element_id, $key )
					);
				}
			}
		}
		$change['warnings'] = $warn;
		$change['notes']    = $notes;
		return $change;
	}

	/**
	 * Step 5: through Beaver's model, published and draft.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function write( $post, array $change, array $opts ) {
		$id   = (int) $post->ID;
		$plan = array(
			'published' => 'live',
			'draft'     => 'draft',
		);
		FLBuilderModel::set_post_id( $id );
		try {
			foreach ( $plan as $status => $flag ) {
				$todo = array();
				foreach ( $change['changes'] as $c ) {
					if ( ! empty( $c['data'][ $flag ] ) ) {
						$todo[] = $c;
					}
				}
				if ( array() === $todo ) {
					continue;
				}
				$data = FLBuilderModel::get_layout_data( $status, $id );
				if ( ! is_array( $data ) ) {
					return new WP_Error( 'ab_mcp_builder_write_failed', __( 'Beaver Builder returned no layout for this page.', 'alphabridge-mcp' ) );
				}
				foreach ( self::by_element( $todo ) as $node_id => $list ) {
					if ( ! isset( $data[ $node_id ] ) || ! is_object( $data[ $node_id ] ) || ! isset( $data[ $node_id ]->settings ) ) {
						return self::gone( $node_id, 'settings' );
					}
					$node = $data[ $node_id ];
					if ( 'module' === ( isset( $node->type ) ? $node->type : '' ) && isset( $node->settings->type ) && method_exists( 'FLBuilderModel', 'is_module_registered' ) && ! FLBuilderModel::is_module_registered( (string) $node->settings->type ) ) {
						return new WP_Error(
							'ab_mcp_builder_cannot_write',
							/* translators: %s: module type */
							sprintf( __( 'The Beaver Builder module %s is not registered on this site (its plugin may be inactive), so Beaver cannot process its settings. Nothing was changed. Activate the plugin that provides it, or change the element in Beaver Builder\'s editor.', 'alphabridge-mcp' ), (string) $node->settings->type )
						);
					}
					$copy     = self::deep_copy( $node );
					$settings = null === $copy ? null : self::deep_copy( FLBuilderModel::get_node_settings_with_defaults_merged( $copy ) );
					if ( ! is_object( $settings ) ) {
						return new WP_Error(
							'ab_mcp_builder_cannot_write',
							/* translators: %s: element id */
							sprintf( __( 'The settings of element %s hold data of another plugin that AlphaBridge does not copy, so it does not save them. Nothing was changed. Change the element in Beaver Builder\'s editor.', 'alphabridge-mcp' ), $node_id )
						);
					}
					foreach ( $list as $c ) {
						if ( ! self::set_at( $settings, $c['data']['path'], $c['after'] ) ) {
							return self::gone( $node_id, $c['field'] );
						}
						foreach ( isset( $c['data']['also'] ) ? (array) $c['data']['also'] : array() as $path => $value ) {
							if ( ! self::set_at( $settings, (string) $path, $value ) && is_object( $settings ) ) {
								// photo_src is missing on a photo that never had one; Beaver adds it the same way.
								$settings->{$path} = $value;
							}
						}
					}
					$processed = FLBuilderModel::process_node_settings( $node, $settings );
					if ( ! is_object( $processed ) ) {
						return new WP_Error( 'ab_mcp_builder_write_failed', __( 'Beaver Builder did not return the processed settings.', 'alphabridge-mcp' ) );
					}
					$data[ $node_id ]->settings = $processed;
				}
				FLBuilderModel::update_layout_data( $data, $status, $id );
			}
		} finally {
			FLBuilderModel::reset_post_id();
		}
		return true;
	}

	/**
	 * Step 6: the live page through the outline (as the base does), and every
	 * change made in the draft there.
	 *
	 * @param WP_Post $post       Post.
	 * @param array   $change     Change.
	 * @param array   $ids_before Element ids before.
	 * @return true|WP_Error
	 */
	protected function readback( $post, array $change, array $ids_before ) {
		$live = array();
		foreach ( $change['changes'] as $c ) {
			if ( ! empty( $c['data']['live'] ) ) {
				$live[] = $c;
			}
		}
		if ( array() !== $live ) {
			$ok = parent::readback( $post, array_merge( $change, array( 'changes' => $live ) ), $ids_before );
			if ( true !== $ok ) {
				return $ok;
			}
		}
		$drafts = get_post_meta( (int) $post->ID, self::DRAFT, false );
		$draft  = array() !== $drafts ? reset( $drafts ) : null;
		$off    = array();
		$wrote  = false;
		foreach ( $change['changes'] as $c ) {
			if ( empty( $c['data']['draft'] ) ) {
				continue;
			}
			$wrote = true;
			$node  = self::node( $draft, $c['element'] );
			$have  = null === $node ? null : self::value_at( $node->settings, $c['data']['path'] );
			$want  = array_key_exists( 'expect', $c ) ? $c['expect'] : $c['after'];
			if ( null === $have || ! is_scalar( $have ) || self::norm( $c['kind'], $have ) !== self::norm( $c['kind'], $want ) ) {
				$off[] = $c['element'] . ':' . $c['field'];
			}
		}
		if ( $wrote && ( ! is_array( $draft ) || self::node_ids( $draft ) !== ( isset( $change['draft_ids'] ) ? $change['draft_ids'] : array() ) ) ) {
			return new WP_Error( 'ab_mcp_builder_readback_elements', __( 'After the save Beaver Builder\'s draft did not hold the same elements as before.', 'alphabridge-mcp' ) );
		}
		if ( array() !== $off ) {
			return new WP_Error(
				'ab_mcp_builder_readback_fields',
				/* translators: %s: element:field list */
				sprintf( __( 'After the save these fields of Beaver Builder\'s draft do not show their new value: %s.', 'alphabridge-mcp' ), implode( ', ', array_slice( $off, 0, 20 ) ) )
			);
		}
		return true;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * A node of a layout by id, or null.
	 *
	 * @param mixed  $layout Layout (array of node objects keyed by id).
	 * @param string $id     Node id.
	 * @return object|null
	 */
	private static function node( $layout, $id ) {
		if ( ! is_array( $layout ) || ! isset( $layout[ (string) $id ] ) || ! is_object( $layout[ (string) $id ] ) ) {
			return null;
		}
		$node = $layout[ (string) $id ];
		return isset( $node->settings ) && ( is_object( $node->settings ) || is_array( $node->settings ) ) ? $node : null;
	}

	/**
	 * The node ids of a layout, sorted.
	 *
	 * @param mixed $layout Layout.
	 * @return string[]
	 */
	private static function node_ids( $layout ) {
		$ids = is_array( $layout ) ? array_map( 'strval', array_keys( $layout ) ) : array();
		sort( $ids, SORT_STRING );
		return $ids;
	}

	/**
	 * The address Beaver renders for a new photo: the image size the old
	 * address was (found in the cached attachment data, settings->data->sizes),
	 * where the new image has it; else its full size.
	 *
	 * @param object|null $settings Settings of the photo module as stored.
	 * @param int         $id       New attachment id.
	 * @return string
	 */
	private static function photo_src( $settings, $id ) {
		$src   = null === $settings ? null : self::value_at( $settings, 'photo_src' );
		$sizes = null === $settings ? null : self::value_at( $settings, 'data.sizes' );
		$size  = '';
		if ( is_string( $src ) && '' !== $src && ( is_array( $sizes ) || is_object( $sizes ) ) ) {
			foreach ( (array) $sizes as $key => $entry ) {
				$url = self::value_at( $entry, 'url' );
				if ( is_string( $url ) && $url === $src ) {
					$size = (string) $key;
					break;
				}
			}
		}
		if ( '' !== $size && 'full' !== $size && function_exists( 'wp_get_attachment_image_src' ) ) {
			$img = wp_get_attachment_image_src( (int) $id, $size );
			if ( is_array( $img ) && isset( $img[0] ) && is_string( $img[0] ) && '' !== $img[0] ) {
				return $img[0];
			}
		}
		$url = wp_get_attachment_url( (int) $id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Whether Beaver counts the account as allowed unfiltered HTML (its own
	 * filter included).
	 *
	 * @return bool
	 */
	private static function unfiltered() {
		if ( method_exists( 'FLBuilderModel', 'user_has_unfiltered_html' ) ) {
			return (bool) FLBuilderModel::user_has_unfiltered_html();
		}
		return current_user_can( 'unfiltered_html' );
	}
}

AB_MCP_Builder_Writers::register_class( 'AB_MCP_Builder_Writer_Beaver' );
