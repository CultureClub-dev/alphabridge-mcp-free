<?php
/**
 * What wp_duplicate_post takes over from the original besides the post row
 * and its terms: custom fields, page builder layouts, page template and
 * featured image.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Duplicate_Meta
 */
class AB_MCP_Duplicate_Meta extends AB_MCP_Tools_Base {

	/**
	 * Protected keys WordPress writes itself that describe the content, so a
	 * copy needs them to look like its original. _wp_ignored_hooked_blocks
	 * lists the hooked blocks the editor removed; WordPress reads it when it
	 * renders the content, and without it they would appear again on the copy.
	 */
	const WORDPRESS_KEYS = array( '_wp_page_template', '_thumbnail_id', '_wp_ignored_hooked_blocks' );

	/**
	 * Reasons a builder key stays behind that take the builder's other keys,
	 * its page template included, along: the copy would otherwise be marked
	 * as built with the builder but lack its layout. Caches and
	 * credential-like keys are not part of the layout and leave the rest as
	 * it is.
	 */
	const SET_BREAKING = array( 'needs_unfiltered_html', 'not_allowed', 'not_storable' );

	/**
	 * Keys that record the original's life rather than its content. On the
	 * copy each would mislead WordPress:
	 * - _edit_lock, _edit_last: the original's editor would hold the copy's
	 *   lock and appear as its last editor;
	 * - _wp_old_slug, _wp_old_date: the original's former addresses, which
	 *   WordPress redirects to whichever post carries them;
	 * - _wp_desired_post_slug and _wp_trash_meta_*: what the trash keeps to
	 *   restore a post — the copy is a fresh draft;
	 * - _encloseme, _pingme: enclosure and pingback jobs not yet run, which
	 *   the copy would send again under its own address.
	 */
	const ORIGINAL_ONLY_KEYS     = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug', '_encloseme', '_pingme' );
	const ORIGINAL_ONLY_PREFIXES = array( '_wp_trash_meta_' );

	/**
	 * Page builders and the post meta they keep. Elementor, Beaver Builder,
	 * SiteOrigin, Themify, Zion, Live Composer and Brizy render from it, and
	 * post_content holds at most a copy of the text, so without these keys a
	 * copy of their pages is a plain page; Visual Composer, SeedProd and
	 * Pagelayer render from post_content but keep what their editor opens in
	 * meta (all measured 30.09.2026, recherche/2026-09-30-page-builder-messung).
	 *
	 * - keys / prefixes: the builder's meta, as measured on a real install
	 *   or, for Themify's page CSS and JavaScript, read in its code.
	 * - flags: key => value that marks a post as built with it (null: any
	 *   non-empty value), the builder's own test.
	 * - caches: data the builder derives from the layout and works out again
	 *   for the copy. Of the builders measured only Elementor keeps such data
	 *   in post meta; the others, where they cache at all, use files or
	 *   transients.
	 * - templates: prefixes of the builder's own values of _wp_page_template;
	 *   such a template goes and stays with the builder's keys.
	 *
	 * A site can add a builder through the ab_mcp_duplicate_builders filter.
	 *
	 * @return array<string,array>
	 */
	public static function builders() {
		$builders = array(
			'elementor'       => array(
				'prefixes'  => array( '_elementor_' ),
				'flags'     => array( '_elementor_edit_mode' => 'builder' ),
				// elementor_canvas, elementor_header_footer and elementor_theme.
				// Elementor applies them to any page that has them, so without
				// the layout the copy would show the content field's text on an
				// empty canvas.
				'templates' => array( 'elementor_' ),
				// _elementor_css says the original's CSS file exists, so a copy
				// carrying it would never get its own; _elementor_element_cache
				// holds HTML rendered with the original's element ids;
				// _elementor_page_assets is rebuilt when missing; the global-class
				// keys index the original in a list kept on the class itself.
				// _elementor_controls_usage is what Elementor's usage module
				// counted for the original: it takes a page's value off the
				// site-wide count when the page is saved without a change of
				// status or deleted, so a draft copy carrying it would lower a
				// count it was never added to. Elementor counts a page when it
				// is published.
				'caches'    => array(
					'_elementor_controls_usage',
					'_elementor_css',
					'_elementor_element_cache',
					'_elementor_page_assets',
					'_elementor_global_class_usage_indexed',
					'_elementor_global_class_usage_indexed_preview',
					'_elementor_used_global_class',
					'_elementor_used_global_class_preview',
					'_elementor_global_class_using_documents',
					'_elementor_global_class_using_documents_preview',
				),
			),
			'beaver-builder'  => array(
				'prefixes' => array( '_fl_builder_' ),
				'flags'    => array( '_fl_builder_enabled' => '1' ),
			),
			'siteorigin'      => array(
				'keys'  => array( 'panels_data' ),
				'flags' => array( 'panels_data' => null ),
			),
			'themify'         => array(
				'prefixes' => array( '_themify_builder_' ),
				// Page CSS and JavaScript of the builder, printed as they are.
				'keys'     => array( 'tbp_custom_css', 'tbp_custom_js' ),
				'flags'    => array( '_themify_builder_settings_json' => null ),
			),
			'zion'            => array(
				'prefixes' => array( '_zionbuilder_' ),
				'flags'    => array( '_zionbuilder_page_status' => 'enabled' ),
			),
			'live-composer'   => array(
				'keys'  => array( 'dslc_code', 'dslc_content_for_search' ),
				'flags' => array( 'dslc_code' => null ),
			),
			'brizy'           => array(
				'prefixes' => array( 'brizy' ),
				'flags'    => array( 'brizy_enabled' => '1' ),
			),
			'visual-composer' => array(
				'prefixes' => array( 'vcv', '_vcv' ),
				'flags'    => array( 'vcv-pageContent' => null ),
			),
			'seedprod'        => array(
				'prefixes' => array( '_seedprod_' ),
				'flags'    => array(
					'_seedprod_page'                 => '1',
					'_seedprod_edited_with_seedprod' => '1',
				),
			),
			'pagelayer'       => array(
				'prefixes' => array( 'pagelayer' ),
				'flags'    => array( 'pagelayer-data' => null ),
			),
		);

		/**
		 * Page builders whose post meta wp_duplicate_post copies as one set —
		 * with the unfiltered_html capability only, because builder data holds
		 * markup — and whose caches it leaves out.
		 *
		 * @param array<string,array> $builders Slug => keys, prefixes, flags, caches.
		 */
		return (array) apply_filters( 'ab_mcp_duplicate_builders', $builders );
	}

	/**
	 * Copy the original's post meta onto its copy and say what was copied,
	 * what was left out and why.
	 *
	 * Values are copied as stored: the raw value goes in as what it stands
	 * for — add_metadata() serializes an array or object once, but a string
	 * that is already serialized a second time — and is slashed throughout,
	 * objects included, because add_metadata() unslashes throughout — the
	 * key as well. The copy is read back, and a key that does not come out
	 * byte for byte is named.
	 *
	 * @param WP_Post $src    Original.
	 * @param int     $new_id Copy.
	 * @return array Report for the tool response.
	 */
	public static function copy( $src, $new_id ) {
		$builders = self::builders();
		$all      = (array) get_post_meta( $src->ID ); // Every key, values raw as stored.
		$report   = array();

		$built_with = self::built_with( $all, $builders );
		if ( '' !== $built_with ) {
			$report['built_with'] = $built_with;
		}

		// The copy of a revision is a revision of the same post, and
		// add_post_meta() and delete_post_meta() write a revision's meta to
		// the post it belongs to: the live post would get the values.
		$parent = wp_is_post_revision( $new_id );
		if ( false !== $parent ) {
			$report['meta_copied'] = array(
				'count' => 0,
				'keys'  => array(),
			);
			$report['notes']       = array(
				/* translators: %d: id of the post the revision belongs to. */
				sprintf( __( 'The original is a revision, so its copy is a revision of the same post, and WordPress writes the custom fields of a revision to that post. None were copied, so the post stays as it is. To copy the page with its custom fields and layout, duplicate the post it belongs to (id %d).', 'alphabridge-mcp' ), (int) $parent ),
			);
			return $report;
		}

		// Read rights cover the post row, not its meta: WordPress shows custom
		// fields to someone who may edit the post. The copy itself is made as
		// before; the keys are not even named, as they are not readable here.
		if ( ! current_user_can( 'edit_post', $src->ID ) ) {
			$report['meta_copied'] = array(
				'count' => 0,
				'keys'  => array(),
			);
			$report['notes']       = array(
				__( 'Custom fields, featured image, page template and page builder data were not copied: your account may read the original but not edit it. An account that may edit the original gets them copied too.', 'alphabridge-mcp' ),
			);
			return $report;
		}

		$unfiltered = current_user_can( 'unfiltered_html' );
		$present    = (array) get_post_meta( $new_id ); // What WordPress or a plugin already gave the new post.

		// First each key on its own: whether it may go, and its values ready
		// to be written.
		$entries = array();
		foreach ( $all as $key => $values ) {
			$key     = (string) $key;
			$values  = array_values( (array) $values );
			$builder = self::builder_of( $key, $builders );
			$entry   = array(
				'own'      => $builder,
				'set'      => '' !== $builder ? $builder : self::template_builder( $key, $values, $builders ),
				'reason'   => self::skip_reason( $key, $builder, $builders, $unfiltered, $src, $new_id ),
				'values'   => $values,
				'prepared' => array(),
				'renewed'  => null,
				'notes'    => array(),
			);
			$entries[ $key ] = '' === $entry['reason'] ? self::prepare( $key, $entry ) : $entry;
		}

		// Then each builder as a set: one key of it that may not go takes the
		// others along, so the copy is never marked as a builder page that
		// lacks its layout.
		$broken = array();
		foreach ( $entries as $entry ) {
			if ( '' !== $entry['own'] && in_array( $entry['reason'], self::SET_BREAKING, true ) ) {
				$broken[ $entry['own'] ] = ( 'needs_unfiltered_html' === $entry['reason'] || 'needs_unfiltered_html' === ( $broken[ $entry['own'] ] ?? '' ) ) ? 'needs_unfiltered_html' : 'builder_incomplete';
			}
		}
		foreach ( $entries as $key => $entry ) {
			if ( '' === $entry['reason'] && isset( $broken[ $entry['set'] ] ) ) {
				$entries[ $key ]['reason'] = $broken[ $entry['set'] ];
			}
		}

		$copied     = array();
		$written    = array();
		$skipped    = array();
		$notes      = array();
		$builder_in = array();
		foreach ( $entries as $key => $entry ) {
			$key = (string) $key; // PHP turns a numeric key back into an integer.
			if ( '' !== $entry['reason'] ) {
				$skipped[ $entry['reason'] ][] = $key;
				continue;
			}
			// The key goes in slashed, like the values: add_metadata() and
			// delete_metadata() unslash it, and the copy is to carry the key
			// checked above — unslashed, "\_edit_lock" would land as the
			// protected "_edit_lock", past every rule for it.
			$slashed = wp_slash( $key );
			// add_post_meta() adds to what is there; the copy is to hold exactly
			// the original's values.
			if ( isset( $present[ $key ] ) ) {
				delete_post_meta( $new_id, $slashed );
			}
			foreach ( $entry['prepared'] as $value ) {
				add_post_meta( $new_id, $slashed, $value );
			}
			$copied[]        = $key;
			$written[ $key ] = $entry['values'];
			$notes           = array_merge( $notes, $entry['notes'] );
			if ( null !== $entry['renewed'] ) {
				$report['elementor_ids_renewed'] = ( $report['elementor_ids_renewed'] ?? 0 ) + $entry['renewed'];
			}
			if ( '' !== $entry['own'] ) {
				$builder_in[ $entry['own'] ] = true;
			}
		}

		// Read back: a sanitize filter of another plugin, or a value WordPress
		// cannot store as given, shows up here instead of passing as a copy.
		$back    = (array) get_post_meta( $new_id );
		$altered = array();
		foreach ( $written as $key => $values ) {
			if ( array_values( (array) ( $back[ $key ] ?? array() ) ) !== $values ) {
				$altered[] = (string) $key;
			}
		}

		foreach ( array_keys( $builder_in ) as $builder ) {
			if ( 'elementor' !== $builder ) {
				/* translators: %s: page builder slug, e.g. beaver-builder. */
				$notes[] = sprintf( __( 'The %s data was copied byte for byte; ids inside it were not renewed.', 'alphabridge-mcp' ), $builder );
			}
		}

		$report['meta_copied'] = array(
			'count' => count( $copied ),
			'keys'  => $copied,
		);
		if ( array() !== $skipped ) {
			$report['meta_skipped'] = array();
			foreach ( $skipped as $reason => $keys ) {
				$report['meta_skipped'][ $reason ] = array(
					'keys' => $keys,
					'why'  => self::why( $reason ),
				);
			}
		}
		if ( array() !== $altered ) {
			$report['meta_altered_on_write'] = $altered;
			$notes[] = __( 'The keys in meta_altered_on_write were stored differently from the original, usually by a sanitize filter of another plugin.', 'alphabridge-mcp' );
		}
		if ( array() !== $notes ) {
			$report['notes'] = $notes;
		}
		return $report;
	}

	/**
	 * A key's values made ready to be written: Elementor element ids renewed,
	 * every value unserialized and slashed. All values first, so a key is
	 * written whole or not at all. An object whose class is not loaded in
	 * this request cannot be changed, not even unslashed, so WordPress could
	 * not store it.
	 *
	 * @param string $key   Meta key.
	 * @param array  $entry The key's entry, values raw.
	 * @return array The entry with prepared values, or with the reason not_storable.
	 */
	private static function prepare( $key, array $entry ) {
		if ( '_elementor_data' === $key ) {
			foreach ( $entry['values'] as $i => $raw ) {
				$renewed = self::renew_elementor_ids( $raw );
				if ( is_wp_error( $renewed ) ) {
					$entry['notes'][] = $renewed->get_error_message();
					continue;
				}
				$entry['values'][ $i ] = $renewed[0];
				$entry['renewed']      = ( $entry['renewed'] ?? 0 ) + $renewed[1];
			}
		}
		try {
			foreach ( $entry['values'] as $raw ) {
				$entry['prepared'][] = self::slash_deep( maybe_unserialize( $raw ) );
			}
		} catch ( Throwable $e ) {
			$entry['prepared'] = array();
			$entry['reason']   = 'not_storable';
		}
		return $entry;
	}

	/**
	 * Whether this site gives unfiltered_html to no account at all:
	 * DISALLOW_UNFILTERED_HTML takes it from administrators and super admins
	 * too (map_meta_cap()), so "use such an account" is no way there.
	 *
	 * @return bool
	 */
	public static function unfiltered_html_disallowed() {
		return defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML;
	}

	/**
	 * Why a key stays behind, or '' when it is copied.
	 *
	 * @param string              $key        Meta key.
	 * @param string              $builder    Builder the key belongs to, or ''.
	 * @param array<string,array> $builders   Builder table.
	 * @param bool                $unfiltered Whether the account has unfiltered_html.
	 * @param WP_Post             $src        Original.
	 * @param int                 $new_id     Copy.
	 * @return string
	 */
	private static function skip_reason( $key, $builder, array $builders, $unfiltered, $src, $new_id ) {
		// First, and for every key: nothing a filter or a builder says can
		// carry a credential into a post the caller owns.
		if ( self::is_sensitive_meta_key( $key ) ) {
			return 'sensitive';
		}
		if ( in_array( $key, self::ORIGINAL_ONLY_KEYS, true ) ) {
			return 'original_only';
		}
		foreach ( self::ORIGINAL_ONLY_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return 'original_only';
			}
		}
		$protected = '_' === substr( $key, 0, 1 ) || is_protected_meta( $key, 'post' );

		if ( '' !== $builder ) {
			if ( in_array( $key, (array) ( $builders[ $builder ]['caches'] ?? array() ), true ) ) {
				return 'cache';
			}
			// Builder data is markup that the builder prints without filtering
			// (HTML widgets, page CSS and JavaScript). Who may not store such
			// markup in a post must not get it into one by copying.
			if ( ! $unfiltered ) {
				return 'needs_unfiltered_html';
			}
			// edit_post_meta denies every protected key unless a plugin opened
			// it, so for the builder's protected keys the edit right on both
			// posts is the check; open keys go through it like any other.
			if ( ! $protected && ! current_user_can( 'edit_post_meta', $new_id, $key ) ) {
				return 'not_allowed';
			}
			return '';
		}

		if ( in_array( $key, self::WORDPRESS_KEYS, true ) ) {
			return '';
		}
		if ( $protected ) {
			/**
			 * Whether wp_duplicate_post copies a protected key that belongs to
			 * neither a page builder nor WordPress — for instance the reference
			 * keys of a custom-field plugin. Credential-like keys are refused
			 * before this filter runs.
			 *
			 * @param bool    $copy Default false.
			 * @param string  $key  Meta key.
			 * @param WP_Post $src  Original.
			 */
			return apply_filters( 'ab_mcp_duplicate_copy_protected_meta', false, $key, $src ) ? '' : 'protected';
		}
		// The rule of wp_get_post_meta and wp_update_post: the key's own edit
		// capability, which honours auth_callback rules other plugins register.
		return current_user_can( 'edit_post_meta', $new_id, $key ) ? '' : 'not_allowed';
	}

	/**
	 * The text for a reason a key stays behind.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private static function why( $reason ) {
		switch ( $reason ) {
			case 'sensitive':
				return __( 'Looks like a credential and is never copied over MCP. If the copy needs it, set it in wp-admin.', 'alphabridge-mcp' );
			case 'original_only':
				return __( 'Belongs to the original, not to its content: edit lock, last editor, former addresses, trash data or pending pings.', 'alphabridge-mcp' );
			case 'cache':
				return __( 'Derived by the page builder from the layout (a cache, or a count such as Elementor\'s control usage); the builder works it out again for the copy when the copy is viewed, saved or published.', 'alphabridge-mcp' );
			case 'needs_unfiltered_html':
				if ( self::unfiltered_html_disallowed() ) {
					return __( 'Page builder data holds markup, which only accounts with the unfiltered_html capability may store, and this site gives that capability to no account, administrators included: it sets DISALLOW_UNFILTERED_HTML in wp-config.php. A builder\'s keys, its page template included, are copied together or not at all, so the copy has the content field only, which for builders that render from post meta holds at most a copy of the text. wp_duplicate_post cannot copy the layout on this site; where the page builder offers its own copy or template function in wp-admin, use that.', 'alphabridge-mcp' );
				}
				return __( 'Page builder data holds markup, which only accounts with the unfiltered_html capability may store (administrators; on a multisite network, super admins). A builder\'s keys, its page template included, are copied together or not at all, so the copy has the content field only, which for builders that render from post meta holds at most a copy of the text. To copy the layout, run wp_duplicate_post with such an account.', 'alphabridge-mcp' );
			case 'builder_incomplete':
				return __( 'Belongs to a page builder one of whose keys stays behind (listed under not_allowed or not_storable, with the reason). A builder\'s keys, its page template included, are copied together or not at all — caches and credential-like keys aside — so the copy is not marked as a builder page without its layout; it has the content field only. Once those keys can be copied, run wp_duplicate_post again.', 'alphabridge-mcp' );
			case 'protected':
				return __( 'Protected key of another plugin. Copied are page builder data and the keys WordPress keeps for the content itself (page template, featured image, removed hooked blocks); a site can allow further keys with the ab_mcp_duplicate_copy_protected_meta filter.', 'alphabridge-mcp' );
			case 'not_storable':
				return __( 'Holds a PHP object of a class that is not loaded in this request; WordPress cannot store such a value through its meta functions. The plugin that owns the key can set it on the copy.', 'alphabridge-mcp' );
			default:
				return __( 'Your account may not edit this key: edit_post_meta says no, usually because the plugin that registered the key restricts it. An account that may edit it can set it on the copy.', 'alphabridge-mcp' );
		}
	}

	/**
	 * The builder a key belongs to, or ''.
	 *
	 * @param string              $key      Meta key.
	 * @param array<string,array> $builders Builder table.
	 * @return string
	 */
	private static function builder_of( $key, array $builders ) {
		foreach ( $builders as $slug => $builder ) {
			if ( in_array( $key, (array) ( $builder['keys'] ?? array() ), true ) ) {
				return (string) $slug;
			}
			foreach ( (array) ( $builder['prefixes'] ?? array() ) as $prefix ) {
				if ( '' !== (string) $prefix && 0 === strpos( $key, (string) $prefix ) ) {
					return (string) $slug;
				}
			}
		}
		return '';
	}

	/**
	 * The builder whose own page template the original uses, or ''. The
	 * template then goes and stays with that builder's keys.
	 *
	 * @param string              $key      Meta key.
	 * @param array               $values   The key's values, raw.
	 * @param array<string,array> $builders Builder table.
	 * @return string
	 */
	private static function template_builder( $key, array $values, array $builders ) {
		if ( '_wp_page_template' !== $key ) {
			return '';
		}
		$template = (string) ( $values[0] ?? '' );
		foreach ( $builders as $slug => $builder ) {
			foreach ( (array) ( $builder['templates'] ?? array() ) as $prefix ) {
				if ( '' !== (string) $prefix && 0 === strpos( $template, (string) $prefix ) ) {
					return (string) $slug;
				}
			}
		}
		return '';
	}

	/**
	 * The builder the original says it is built with, by the builder's own
	 * marker, or ''.
	 *
	 * @param array<string,array> $all      The original's meta, raw.
	 * @param array<string,array> $builders Builder table.
	 * @return string
	 */
	public static function built_with( array $all, array $builders ) {
		foreach ( $builders as $slug => $builder ) {
			foreach ( (array) ( $builder['flags'] ?? array() ) as $key => $expected ) {
				$value = (string) ( ( (array) ( $all[ $key ] ?? array() ) )[0] ?? '' );
				if ( null === $expected ? '' !== trim( $value ) : (string) $expected === $value ) {
					return (string) $slug;
				}
			}
		}
		return '';
	}

	/**
	 * A new id for every element of an Elementor layout, as Elementor gives
	 * them when it copies elements itself (Source_Base::replace_elements_ids()
	 * and Document::get_export_data() in Elementor 4.3.3): the same walk as
	 * DB::iterate_data(), and after each new id the filter
	 * elementor/document/element/replace_id, on which Elementor's atomic
	 * widgets renew the style and interaction ids that carry the element's
	 * id. Without Elementor active nothing listens and the call does nothing.
	 *
	 * The layout is written back the way Elementor stores it,
	 * wp_json_encode() of the decoded array (Document::save_elements()); a
	 * layout Elementor saved comes out byte for byte except for the ids.
	 *
	 * @param string $raw Stored value of _elementor_data.
	 * @return array{0:string,1:int}|WP_Error New value and number of ids, or why the value stays as it is.
	 */
	public static function renew_elementor_ids( $raw ) {
		$tree = json_decode( (string) $raw, true );
		if ( ! is_array( $tree ) ) {
			return new WP_Error( 'ab_mcp_elementor_not_json', __( 'The Elementor data of the original is not valid JSON; it was copied as it is, element ids unchanged.', 'alphabridge-mcp' ) );
		}
		$taken = array();
		self::collect_elementor_ids( $tree, $taken );
		$count = 0;
		try {
			$tree = self::renew_elementor_node( $tree, $taken, $count );
		} catch ( Throwable $e ) {
			/* translators: %s: error message. */
			return new WP_Error( 'ab_mcp_elementor_ids', sprintf( __( 'The Elementor element ids could not be renewed (%s); the layout was copied as it is.', 'alphabridge-mcp' ), $e->getMessage() ) );
		}
		$json = wp_json_encode( $tree );
		if ( false === $json ) {
			return new WP_Error( 'ab_mcp_elementor_ids', __( 'The Elementor layout could not be encoded again; it was copied as it is, element ids unchanged.', 'alphabridge-mcp' ) );
		}
		return array( $json, $count );
	}

	/**
	 * Every element id already in the layout, so no new id repeats one.
	 *
	 * @param array               $node  Layout or part of it.
	 * @param array<string,bool> $taken Ids seen.
	 */
	private static function collect_elementor_ids( array $node, array &$taken ) {
		if ( isset( $node['elType'] ) ) {
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) ) {
				$taken[ (string) $node['id'] ] = true;
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::collect_elementor_ids( $node['elements'], $taken );
			}
			return;
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				self::collect_elementor_ids( $child, $taken );
			}
		}
	}

	/**
	 * Elementor's DB::iterate_data() walk: an element is an array with an
	 * elType, its children sit under "elements", and anything else is a list
	 * of elements. Children first, as there.
	 *
	 * @param array              $node  Layout or part of it.
	 * @param array<string,bool> $taken Ids in use.
	 * @param int                $count Ids given so far.
	 * @return array
	 */
	private static function renew_elementor_node( array $node, array &$taken, &$count ) {
		if ( isset( $node['elType'] ) ) {
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$node['elements'] = self::renew_elementor_node( $node['elements'], $taken, $count );
			}
			$node['id'] = self::new_elementor_id( $taken );
			++$count;
			$filtered = apply_filters( 'elementor/document/element/replace_id', $node );
			return is_array( $filtered ) ? $filtered : $node;
		}
		foreach ( $node as $index => $child ) {
			if ( is_array( $child ) ) {
				$node[ $index ] = self::renew_elementor_node( $child, $taken, $count );
			}
		}
		return $node;
	}

	/**
	 * Seven lowercase hex digits, the form the Elementor editor gives a new
	 * element (elementorCommon.helpers.getUniqueId():
	 * Math.random().toString(16).substr(2, 7)); Elementor's PHP side,
	 * Utils::generate_random_string(), writes dechex( rand() ), up to eight.
	 *
	 * @param array<string,bool> $taken Ids in use; the new one is added.
	 * @return string
	 */
	private static function new_elementor_id( array &$taken ) {
		do {
			$id = sprintf( '%07x', wp_rand( 0, 0xfffffff ) );
		} while ( isset( $taken[ $id ] ) );
		$taken[ $id ] = true;
		return $id;
	}

	/**
	 * Slashes every string, inside objects too. wp_slash() stops at objects,
	 * but add_metadata() unslashes through them (stripslashes_deep() is
	 * map_deep()), so a backslash in an object — Beaver Builder stores its
	 * nodes as stdClass — would be lost.
	 *
	 * @param mixed $value Value as WordPress hands it out.
	 * @return mixed
	 */
	private static function slash_deep( $value ) {
		return map_deep(
			$value,
			static function ( $item ) {
				return is_string( $item ) ? addslashes( $item ) : $item;
			}
		);
	}
}
