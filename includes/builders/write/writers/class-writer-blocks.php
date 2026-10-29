<?php
/**
 * Writer for pages made of blocks (storage A): the WordPress core blocks and
 * the block libraries the free core has profiles for — GenerateBlocks 1.x and
 * 2.x, Kadence, Spectra, Stackable, Pagelayer. One writer for all of them, as
 * one reader (AB_MCP_Builder_Adapter_Blocks, id "blocks") reads them all: the
 * libraries differ only in their profiles, which are data.
 *
 * How a change is made (plan §6, «Blöcke (A)»; T4 of the plan review):
 * - parse_blocks(), the block by its outline id: the library's own id where
 *   the reader uses it, else the path b0.2.1;
 * - where a field lives comes from the block's profile entry, the same entry
 *   the reader read it with, and the element in the block's markup is found
 *   with the reader's own selector code (AB_MCP_Builder_Html::find());
 * - text: the element's one text node through WP_HTML_Tag_Processor's
 *   set_modifiable_text(); an element with formatting inside is not
 *   flattened, its words are changed with replace() instead;
 * - html: everything between the start and the end tag (two bookmarks,
 *   AB_MCP_Block_Markup), refused where that span holds a style, a
 *   script or an SVG icon; the value has passed wp_kses_post() field by field
 *   (AB_MCP_Builder_Fields);
 * - links and image addresses: set_attribute(); the copies a profile names
 *   ("also": GenerateBlocks htmlAttributes.href/src, Spectra label, link,
 *   url, urlTablet, urlMobile, Stackable imageUrl, Pagelayer text) are kept in
 *   step where they still were copies of the old value;
 * - Kadence's button text lives only in the block comment and Kadence prints
 *   it unfiltered: it is stored HTML-escaped; its link runs through
 *   do_shortcode(), so a "[" is refused (the field rules refuse it for every
 *   link);
 * - serialize_blocks(), then wp_update_post( wp_slash() ) — without the
 *   slashes WordPress strips the backslashes of \u0026 and the attributes
 *   break (measured). The save hooks renew the caches of GenerateBlocks,
 *   Spectra and Stackable (measured).
 * A page whose markup does not come back byte for byte from parse_blocks()
 * and serialize_blocks() is only read: writing it would change more than the
 * one field (measured byte-identical for all ten vendor samples).
 *
 * Pagelayer: its abilities keep a second copy of the page as a tree in the
 * meta pagelayer-data and rewrite post_content from it. That tree is changed
 * along only where it matches the page as it was; a stale tree (Pagelayer's
 * editor does not keep it) is left alone and the answer says what follows.
 *
 * replace() changes the words of every writable field by the same rules, on
 * the decoded text: a search for «A & B» finds the HTML text A &amp; B and
 * the attribute A \u0026 B alike, and the replacement is written back in the
 * form each place stores it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Writer_Blocks
 */
final class AB_MCP_Builder_Writer_Blocks extends AB_MCP_Builder_Writer {

	/** A path id of the block reader: b + indices. */
	const PATH = '~^b(\d+(?:\.\d+)*)$~';

	/** Deepest nesting followed when looking for a library id. */
	const MAX_DEPTH = 512;

	/** Image classes that carry the attachment id, kept in step with it. */
	const ID_CLASS = '~^(wp-image|uag-image)-(\d+)$~';

	/** Attributes of inline markup a replace may change: link and image addresses. */
	const URL_ATTRS = array( 'href', 'src' );

	/** Attributes of inline markup a replace may change: texts. */
	const TEXT_ATTRS = array( 'alt', 'title' );

	/** The meta Pagelayer's abilities keep their tree of the page in. */
	const PAGELAYER_META = 'pagelayer-data';

	/**
	 * Block profiles by block name (see AB_MCP_Block_Reader).
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * Round-trip results by content hash, for this request.
	 *
	 * @var array<string,bool>
	 */
	private static $roundtrip = array();

	/**
	 * The adapter id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'blocks';
	}

	/**
	 * Forget the profiles and round trips seen in this request.
	 */
	public static function reset() {
		self::$index     = null;
		self::$roundtrip = array();
	}

	/**
	 * Whether this page can be written: the HTML API that sets text nodes
	 * (WordPress 6.7), and markup that WordPress reads and writes back byte
	 * for byte.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function can_write( $post ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) || ! self::load_markup() || ! AB_MCP_Block_Markup::ab_supported() ) {
			return new WP_Error(
				'ab_mcp_builder_wp_too_old',
				__( 'Changing block elements needs WordPress 6.7 or newer, whose HTML API can set the text of an element. On this site AlphaBridge only reads block pages: change the text with wp_update_post (read the page with wp_get_post and keep the block markup as it is) or in the block editor.', 'alphabridge-mcp' )
			);
		}
		if ( ! is_object( $post ) || ! self::round_trips( (string) $post->post_content ) ) {
			return new WP_Error(
				'ab_mcp_builder_no_roundtrip',
				__( 'The block markup of this page does not come back byte for byte when WordPress reads and writes it again, so writing one element would change other parts of the page too. AlphaBridge only reads it. Change the text with wp_update_post (read the page with wp_get_post and keep the block markup as it is), or open and save the page once in the block editor, which writes the markup in its own form.', 'alphabridge-mcp' )
			);
		}
		return true;
	}

	/* ------------------------------------------------------------ set_fields */

	/**
	 * Change fields of one block.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id of the outline.
	 * @param array   $fields     Field name (or kind alias) => value.
	 * @param array   $opts       See execute().
	 * @return array|WP_Error
	 */
	public function set_fields( $post, $element_id, array $fields, array $opts ) {
		$post = is_object( $post ) ? self::fresh_post( (int) $post->ID ) : null;
		if ( null === $post ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found. wp_list_posts or wp_search finds its id.', 'alphabridge-mcp' ) );
		}
		$can = $this->can_write( $post );
		if ( is_wp_error( $can ) ) {
			return $can;
		}
		$el = $this->element( $post, $element_id );
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$changes = $this->fields( $el, $fields );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$blocks = parse_blocks( (string) $post->post_content );
		$path   = self::locate( $blocks, (string) $el['id'] );
		if ( null === $path ) {
			return self::not_found( (string) $el['id'] );
		}
		$block = self::block_at( $blocks, $path );
		$plan  = $this->plan_set( $block, $el, $changes );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$done = $this->finish( $post, $blocks, self::put_block( $blocks, $path, $plan['block'] ), $plan['changes'] );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return $this->execute(
			$post,
			array(
				'element'  => $el,
				'changes'  => $plan['changes'],
				'blocks'   => $done['blocks'],
				'warnings' => array_merge( $plan['warnings'], $done['warnings'] ),
				'notes'    => array_merge( $plan['notes'], $done['notes'] ),
			),
			$opts
		);
	}

	/* --------------------------------------------------------------- replace */

	/**
	 * Replace a text in every writable field of the page.
	 *
	 * $opts beyond execute(): case_insensitive (bool). regex is not taken:
	 * a pattern cannot be told apart from the markup it would match.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $search  Text to find.
	 * @param string  $replace Replacement.
	 * @param array   $opts    Options.
	 * @return array|WP_Error
	 */
	public function replace( $post, $search, $replace, array $opts ) {
		$post = is_object( $post ) ? self::fresh_post( (int) $post->ID ) : null;
		if ( null === $post ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found. wp_list_posts or wp_search finds its id.', 'alphabridge-mcp' ) );
		}
		$search  = (string) $search;
		$replace = (string) $replace;
		if ( '' === $search ) {
			return new WP_Error( 'ab_mcp_builder_no_search', __( 'There is no text to search for. Pass the text to find as search.', 'alphabridge-mcp' ) );
		}
		if ( ! empty( $opts['regex'] ) ) {
			return new WP_Error( 'ab_mcp_builder_regex', __( 'A regular expression is not applied to the fields of a block page: it could match the markup around them. Search for the text itself, or run wp_search_replace with scope: raw.', 'alphabridge-mcp' ) );
		}
		$can = $this->can_write( $post );
		if ( is_wp_error( $can ) ) {
			return $can;
		}
		$adapter = $this->adapter();
		if ( null === $adapter ) {
			return new WP_Error( 'ab_mcp_builder_no_reader', __( 'The reader of this page builder is not loaded on this site. Change the page in its editor in wp-admin.', 'alphabridge-mcp' ) );
		}
		$ci       = ! empty( $opts['case_insensitive'] );
		$content  = (string) $post->post_content;
		$blocks   = parse_blocks( $content );
		$new      = $blocks;
		$changes  = array();
		$warnings = array();
		$notes    = array();
		$replaced = 0;
		$skipped  = 0;
		foreach ( $adapter->outline( $post, $this->outline_opts() ) as $el ) {
			if ( ! empty( $el['locked'] ) || empty( $el['fields'] ) || ! isset( $el['id'] ) ) {
				continue;
			}
			$path = self::locate( $blocks, (string) $el['id'] );
			if ( null === $path ) {
				continue;
			}
			$entry = self::entry( (string) self::block_at( $blocks, $path )['blockName'] );
			if ( null === $entry['spec'] ) {
				// Read with the general reader: where its text lives is not known.
				continue;
			}
			$res = $this->plan_swap( self::block_at( $new, $path ), $el, $entry, $search, $replace, $ci );
			if ( array() === $res['changes'] ) {
				$skipped += $res['skipped'];
				$notes    = array_merge( $notes, $res['notes'] );
				continue;
			}
			$new       = self::put_block( $new, $path, $res['block'] );
			$changes   = array_merge( $changes, $res['changes'] );
			$replaced += $res['count'];
			$skipped  += $res['skipped'];
			$warnings  = array_merge( $warnings, $res['warnings'] );
			$notes     = array_merge( $notes, $res['notes'] );
		}
		$locked = max( 0, self::raw_count( $content, $search, $ci ) - $replaced - $skipped );
		if ( $skipped > 0 ) {
			/* translators: %d: number of matches */
			$notes[] = sprintf( _n( '%d match in a writable field was left: changing it would have broken a link, a placeholder or the field\'s rules (see the notes).', '%d matches in writable fields were left: changing them would have broken a link, a placeholder or the field\'s rules (see the notes).', $skipped, 'alphabridge-mcp' ), $skipped );
		}
		if ( $locked > 0 ) {
			/* translators: %d: number of matches */
			$notes[] = sprintf( _n( '%d further match is outside the fields AlphaBridge changes here (a locked element such as an HTML or code block, a block setting, a style, a block name). Run wp_search_replace again with scope: raw to replace it too.', '%d further matches are outside the fields AlphaBridge changes here (locked elements such as HTML or code blocks, block settings, styles, block names). Run wp_search_replace again with scope: raw to replace them too.', $locked, 'alphabridge-mcp' ), $locked );
		}
		if ( array() === $changes ) {
			return new WP_Error(
				'ab_mcp_builder_nothing',
				/* translators: %s: search text */
				sprintf( __( 'No writable field of this page contains «%s». wp_get_builder_layout shows its fields; a match in markup, code or settings is replaced only by wp_search_replace with scope raw.', 'alphabridge-mcp' ), $search ) . ( array() !== $notes ? ' ' . implode( ' ', array_values( array_unique( $notes ) ) ) : '' ),
				array( 'locked_matches' => $locked )
			);
		}
		$done = $this->finish( $post, $blocks, $new, $changes );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return $this->execute(
			$post,
			array(
				'changes'        => $changes,
				'locked_matches' => $locked,
				'blocks'         => $done['blocks'],
				'warnings'       => array_merge( $warnings, $done['warnings'] ),
				'notes'          => array_merge( $notes, $done['notes'] ),
			),
			$opts
		);
	}

	/* -------------------------------------------------------- the seven steps */

	/**
	 * Step 1, plus the one rule that depends on the whole new page: for an
	 * account WordPress filters with kses (no unfiltered_html), its save
	 * filters the whole post_content, not only the changed field. Where that
	 * would change the page beyond the change, nothing is written.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function step_permission( $post, array $change, array $opts ) {
		$ok = parent::step_permission( $post, $change, $opts );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! isset( $change['blocks']['content'] ) || ! is_string( $change['blocks']['content'] ) ) {
			return new WP_Error( 'ab_mcp_builder_bad_change', __( 'A change is not in the form a writer hands over. Change it in the block editor.', 'alphabridge-mcp' ) );
		}
		return self::save_filter_guard( $change['blocks']['content'] );
	}

	/**
	 * Step 5: the page through WordPress' own save, slashed; then Pagelayer's
	 * tree, where it was in step with the page.
	 *
	 * @param WP_Post $post   Post as read right before.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function write( $post, array $change, array $opts ) {
		$plan = isset( $change['blocks'] ) && is_array( $change['blocks'] ) ? $change['blocks'] : array();
		if ( ! isset( $plan['base'], $plan['content'] ) ) {
			return new WP_Error( 'ab_mcp_builder_bad_change', __( 'A change is not in the form a writer hands over. Change it in the block editor.', 'alphabridge-mcp' ) );
		}
		// The page as stored now, not as execute() read it: somebody may have
		// saved it while the undo point was being recorded.
		$now = self::fresh_post( (int) $post->ID );
		if ( null === $now || ! hash_equals( (string) $plan['base'], hash( 'sha256', (string) $now->post_content ) ) ) {
			return new WP_Error( 'ab_mcp_builder_changed_meanwhile', __( 'The page was saved by someone else while this change was being prepared, and their version is kept. Read it again with wp_get_builder_layout and repeat the change.', 'alphabridge-mcp' ), array( 'written' => false ) );
		}
		$id  = (int) $post->ID;
		$res = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => (string) $plan['content'],
				)
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( (int) $res !== $id ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'WordPress did not save the page.', 'alphabridge-mcp' ) );
		}
		if ( isset( $plan['pagelayer'] ) && is_array( $plan['pagelayer'] ) ) {
			// update_post_meta() unslashes what it is given, as wp_update_post() does.
			update_post_meta( $id, self::PAGELAYER_META, wp_slash( $plan['pagelayer'] ) );
			if ( get_post_meta( $id, self::PAGELAYER_META, true ) !== $plan['pagelayer'] ) {
				return new WP_Error( 'ab_mcp_builder_write_failed', __( 'WordPress did not save Pagelayer\'s copy of the page (pagelayer-data).', 'alphabridge-mcp' ) );
			}
		}
		return true;
	}

	/* ------------------------------------------------- planning a set_fields */

	/**
	 * The new block and the changes, for set_fields().
	 *
	 * @param array $block   Block as parsed.
	 * @param array $el      Outline element.
	 * @param array $changes Changes from AB_MCP_Builder_Fields.
	 * @return array|WP_Error { block, changes, warnings, notes }
	 */
	private function plan_set( array $block, array $el, array $changes ) {
		$name  = (string) $block['blockName'];
		$entry = self::entry( $name );
		$spec  = $entry['spec'];
		if ( null === $spec ) {
			return new WP_Error(
				'ab_mcp_builder_unprofiled',
				/* translators: 1: element id, 2: block name */
				sprintf( __( 'Element %1$s (%2$s) is only read: AlphaBridge has no profile for this block, so it does not know which part of its markup holds the text and which holds settings. Change it in the block editor, or with wp_update_post (keep the block markup as it is).', 'alphabridge-mcp' ), (string) $el['id'], $name )
			);
		}
		$type = '' !== $spec['type'] ? $spec['type'] : $name;
		if ( ! isset( $el['type'] ) || (string) $el['type'] !== $type ) {
			return self::not_found( (string) $el['id'] );
		}
		$own = self::own( $block );

		// A new image brings its address: the page shows the markup, not the
		// id (measured for core, GenerateBlocks, Kadence, Spectra, Stackable).
		$ids       = null;
		$requested = array();
		foreach ( $changes as $c ) {
			$requested[ $c['field'] ] = true;
		}
		foreach ( $changes as $c ) {
			if ( 'image' !== $c['kind'] ) {
				continue;
			}
			$ids = array( (int) $c['before'], (int) $c['after'] );
			$src = isset( $spec['fields']['src'] ) ? $spec['fields']['src'] : null;
			if ( null !== $src && 'url' === $src['kind'] && 'html_attr' === $src['from'] && 'src' === $src['attr'] && isset( $el['fields']['src'] ) && ! isset( $requested['src'] ) && isset( $c['url'] ) && '' !== (string) $c['url'] ) {
				$changes[] = array(
					'element' => (string) $el['id'],
					'type'    => $type,
					'field'   => 'src',
					'kind'    => 'url',
					'before'  => self::read_value( $block, $own, $src ),
					'after'   => (string) $c['url'],
				);
			}
		}

		$ops      = array();
		$warnings = array();
		$notes    = array();
		foreach ( $changes as $i => $c ) {
			$f = isset( $spec['fields'][ $c['field'] ] ) ? $spec['fields'][ $c['field'] ] : null;
			if ( null === $f || $f['kind'] !== $c['kind'] ) {
				return self::not_found( (string) $el['id'] );
			}
			if ( ! empty( $c['truncated'] ) ) {
				// The outline cut it; the full stored value is what the
				// placeholders are checked on (execute(), step 1).
				$changes[ $i ]['before'] = self::read_value( $block, $own, $f );
				unset( $changes[ $i ]['truncated'] );
			}
			if ( ! self::measured( $spec, $f ) ) {
				$warnings[] = sprintf(
					/* translators: 1: field name, 2: block name */
					__( 'profile not yet measured: where %2$s keeps the field %1$s comes from the block\'s code, not from a measurement on a real installation. Check the page after the change.', 'alphabridge-mcp' ),
					$c['field'],
					$name
				);
			}
			$op = self::op_set( $block, $own, $f, $c, $type, $entry['builder'], 'src' === $c['field'] ? $ids : null );
			if ( is_wp_error( $op ) ) {
				return self::with_element( $op, (string) $el['id'] );
			}
			$warnings = array_merge( $warnings, $op['warnings'] );
			$notes    = array_merge( $notes, $op['notes'] );
			$changes[ $i ]['expect'] = $op['expect'];
			$ops[]                   = $op;
		}
		$new = self::apply_ops( $block, $ops );
		if ( null === $new ) {
			return self::split_error( (string) $el['id'] );
		}
		return array(
			'block'    => $new,
			'changes'  => $changes,
			'warnings' => $warnings,
			'notes'    => $notes,
		);
	}

	/**
	 * One field set to a new value: the markup changes and attribute values
	 * that do it, with the copies of the field kept in step.
	 *
	 * @param array      $block   Block.
	 * @param string     $own     Its own markup.
	 * @param array      $f       Field of the profile.
	 * @param array      $c       Change (after checked by its kind).
	 * @param string     $type    Element type.
	 * @param string     $builder Builder id of the profile.
	 * @param array|null $ids     [ old, new ] attachment id, when an image changes with this src.
	 * @return array|WP_Error { updates, attrs, expect, warnings, notes }
	 */
	private static function op_set( array $block, $own, array $f, array $c, $type, $builder, $ids ) {
		$kind  = $f['kind'];
		$plain = 'heading' === $kind || ( 'text' === $kind && AB_MCP_Builder_Fields::plain_only( $kind, $type ) );
		$op    = array(
			'updates'  => array(),
			'attrs'    => array(),
			'expect'   => $c['after'],
			'warnings' => array(),
			'notes'    => array(),
		);
		switch ( $f['from'] ) {
			case 'attr':
				$old = self::attr_get( $block, $f['path'] );
				if ( 'image' === $kind ) {
					$value = is_string( $old ) ? (string) (int) $c['after'] : (int) $c['after'];
				} elseif ( 'url' === $kind ) {
					$value = (string) $c['after'];
				} else {
					$value        = $plain ? AB_MCP_Block_Markup::ab_text_html( (string) $c['after'] ) : self::html_value( (string) $c['after'] );
					$op['expect'] = $value;
					if ( 'pagelayer' === $builder && $plain && 1 === preg_match( '~[&<>]~', (string) $c['after'] ) ) {
						$op['warnings'][] = __( 'Pagelayer keeps this label in the block comment; it is stored with &, < and > written as HTML entities. How Pagelayer shows them has not been measured: check the page after the change.', 'alphabridge-mcp' );
					}
				}
				$op['attrs'][] = array( $f['path'], $value );
				break;

			case 'html_attr':
				$found = AB_MCP_Builder_Html::find( $own, $f['selector'] );
				$p     = self::markup( $own );
				if ( null === $found || ! $p->ab_seek_start_tag( $found['start'] ) ) {
					return self::markup_error();
				}
				if ( 'url' === $kind ) {
					$url = (string) $c['after'];
					$ok  = self::url_kept( $url );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
					$was = $p->get_attribute( $f['attr'] );
					if ( ! $p->set_attribute( $f['attr'], $url ) ) {
						return self::markup_error();
					}
					if ( 'src' === $f['attr'] && 'IMG' === $p->get_tag() ) {
						$set = $p->get_attribute( 'srcset' );
						$own_set = is_string( $set ) ? self::srcset_rewritten( $set, self::old_addresses( $block, $f, $was ), $url ) : null;
						if ( null !== $own_set ) {
							// A srcset that lists only the image's own
							// addresses is one the block builds from the copies
							// of the field (Spectra: url, urlTablet, urlMobile);
							// its save() writes it with the new address, so
							// the markup must too or the editor finds the
							// block invalid. sizes is fixed text there.
							$p->set_attribute( 'srcset', $own_set );
							$op['notes']['srcset'] = __( 'srcset listed only the image\'s own address, as the block builds it from its copies of the address; it lists the new address now, and sizes stayed.', 'alphabridge-mcp' );
						} else {
							// srcset and sizes list other files of the old
							// image (its sizes); a browser would keep showing them.
							foreach ( array( 'srcset', 'sizes' ) as $attr ) {
								if ( null !== $p->get_attribute( $attr ) ) {
									$p->remove_attribute( $attr );
									$op['notes'][ $attr ] = __( 'srcset and sizes were taken off the image: they listed the files of the old image. WordPress adds them for an image of the media library when it shows the page, where the block lets it.', 'alphabridge-mcp' );
								}
							}
						}
						$class = $p->get_attribute( 'class' );
						if ( null !== $ids && is_string( $class ) ) {
							$tokens = preg_split( '~\s+~', trim( $class ) );
							$moved  = false;
							foreach ( is_array( $tokens ) ? $tokens : array() as $k => $token ) {
								if ( 1 === preg_match( self::ID_CLASS, $token, $m ) && (int) $m[2] === $ids[0] ) {
									$tokens[ $k ] = $m[1] . '-' . $ids[1];
									$moved        = true;
								}
							}
							if ( $moved ) {
								$p->set_attribute( 'class', implode( ' ', $tokens ) );
							}
						}
					}
				} elseif ( 'image' !== $kind ) {
					$text = $plain ? (string) $c['after'] : self::attr_text( (string) $c['after'] );
					if ( is_wp_error( $text ) ) {
						return $text;
					}
					if ( ! $p->set_attribute( $f['attr'], $text ) ) {
						return self::markup_error();
					}
					$op['expect'] = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
				} else {
					return self::markup_error();
				}
				$op['updates'] = $p->ab_updates();
				break;

			default: // html_text, html_inner.
				$target = self::target( $own, $f['selector'] );
				if ( is_wp_error( $target ) ) {
					return $target;
				}
				$new          = $plain ? AB_MCP_Block_Markup::ab_text_html( (string) $c['after'] ) : self::html_value( (string) $c['after'] );
				$op['expect'] = $plain ? $new : (string) $c['after'];
				if ( 'whole' === $target ) {
					$op['updates'] = array( array( 0, strlen( $own ), $new ) );
					break;
				}
				$look = self::markup( $own );
				$info = $look->ab_seek_start_tag( $target['start'] ) ? $look->ab_read_to_end_tag( $target['inner_end'] ) : null;
				if ( null === $info ) {
					return self::markup_error();
				}
				$p = self::markup( $own );
				$p->ab_seek_start_tag( $target['start'] );
				if ( $plain ) {
					if ( $info['markup'] ) {
						return new WP_Error(
							'ab_mcp_builder_formatted',
							__( 'This field takes plain text, and the element has formatting inside (bold, a link, a line break): writing plain text would remove it. Change the words with wp_search_replace, which keeps the formatting, or in the block editor.', 'alphabridge-mcp' )
						);
					}
					if ( 1 === $info['text_nodes'] ) {
						$p->next_token();
						if ( ! $p->ab_set_text_node( (string) $c['after'] ) ) {
							return self::markup_error();
						}
					} elseif ( null === $p->ab_read_to_end_tag( $target['inner_end'] ) || ! $p->ab_replace_inner( $new ) ) {
						return self::markup_error();
					}
				} else {
					if ( array() !== $info['hidden'] ) {
						return new WP_Error(
							'ab_mcp_builder_span_hidden',
							/* translators: %s: element names, e.g. "svg" */
							sprintf( __( 'This element holds markup that is not text (%s: an icon, a style or a script); replacing its HTML would remove it. Change the words with wp_search_replace, which keeps it, or edit the element in the block editor.', 'alphabridge-mcp' ), implode( ', ', array_unique( $info['hidden'] ) ) )
						);
					}
					if ( null === $p->ab_read_to_end_tag( $target['inner_end'] ) || ! $p->ab_replace_inner( $new ) ) {
						return self::markup_error();
					}
					$inner = substr( $own, $target['open_end'], $target['inner_end'] - $target['open_end'] );
					if ( $info['markup'] && AB_MCP_Builder_Html::simple( $inner ) !== self::squash( $inner ) ) {
						$op['warnings'][] = __( 'The element\'s HTML carried formatting the outline does not show (classes, styles or other tags); the HTML you sent replaces it.', 'alphabridge-mcp' );
					}
				}
				$op['updates'] = $p->ab_updates();
		}
		if ( null === $op['updates'] ) {
			return self::markup_error();
		}
		return self::with_copies( $block, $own, $f, $op, true );
	}

	/**
	 * The addresses an image field held before a change: the markup's src
	 * and every copy of it in the block's attributes.
	 *
	 * @param array       $block Block.
	 * @param array       $f     Field (src).
	 * @param string|null $src   src as it was (decoded).
	 * @return string[]
	 */
	private static function old_addresses( array $block, array $f, $src ) {
		$out = is_string( $src ) && '' !== $src ? array( $src ) : array();
		foreach ( $f['also'] as $copy ) {
			$have = 'attr' === $copy['from'] ? self::attr_get( $block, $copy['path'] ) : null;
			if ( is_string( $have ) && '' !== $have ) {
				$out[] = $have;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A srcset with every image candidate's address replaced by the new
	 * address — only when each candidate names one of the field's own old
	 * addresses (then the set was built from them, not from other files);
	 * otherwise null. Separators and descriptors stay byte for byte. The
	 * candidates are split the way the HTML standard parses srcset: an
	 * address is a run of non-space characters, trailing commas end it, and
	 * descriptors run to the next comma.
	 *
	 * @param string   $set  srcset as stored (decoded).
	 * @param string[] $olds Old addresses of the field.
	 * @param string   $new  New address.
	 * @return string|null
	 */
	private static function srcset_rewritten( $set, array $olds, $new ) {
		$set   = (string) $set;
		$len   = strlen( $set );
		$spans = array();
		$i     = 0;
		while ( $i < $len ) {
			$i += strspn( $set, " \t\n\r\f,", $i );
			if ( $i >= $len ) {
				break;
			}
			$start = $i;
			$i    += strcspn( $set, " \t\n\r\f", $i );
			$url   = substr( $set, $start, $i - $start );
			$bare  = rtrim( $url, ',' );
			if ( $bare === $url ) {
				// Descriptors (780w, 2x) up to the comma that ends the candidate.
				$i += strcspn( $set, ',', $i );
			}
			if ( '' === $bare || ! in_array( $bare, $olds, true ) ) {
				return null;
			}
			$spans[] = array( $start, strlen( $bare ) );
		}
		if ( array() === $spans ) {
			return null;
		}
		foreach ( array_reverse( $spans ) as $span ) {
			$set = substr_replace( $set, (string) $new, $span[0], $span[1] );
		}
		return $set;
	}

	/* ---------------------------------------------------- planning a replace */

	/**
	 * Every writable field of one block with the search replaced.
	 *
	 * @param array  $block   Block (as changed so far).
	 * @param array  $el      Outline element.
	 * @param array  $entry   entry() of the block.
	 * @param string $search  Search.
	 * @param string $replace Replacement.
	 * @param bool   $ci      Case-insensitive.
	 * @return array { block, changes, count, skipped, warnings, notes }
	 */
	private function plan_swap( array $block, array $el, array $entry, $search, $replace, $ci ) {
		$spec = $entry['spec'];
		$own  = self::own( $block );
		$type = (string) $el['type'];
		$out  = array(
			'block'    => $block,
			'changes'  => array(),
			'count'    => 0,
			'skipped'  => 0,
			'warnings' => array(),
			'notes'    => array(),
		);
		$ops  = array();
		foreach ( $el['fields'] as $name => $field ) {
			$f = isset( $spec['fields'][ $name ] ) ? $spec['fields'][ $name ] : null;
			if ( null === $f || ! in_array( $f['kind'], array( 'text', 'heading', 'html', 'url' ), true ) ) {
				continue;
			}
			$op = self::op_swap( $block, $own, $f, $type, $search, $replace, $ci );
			if ( null === $op ) {
				continue;
			}
			$out['skipped'] += $op['skipped'];
			if ( 0 === $op['count'] ) {
				$out['notes'] = array_merge( $out['notes'], $op['notes'] );
				continue;
			}
			$found = $op['count'];
			$op    = self::with_copies( $block, $own, $f, $op );
			if ( is_wp_error( $op ) ) {
				$out['skipped'] += $found;
				/* translators: 1: element id, 2: field name, 3: reason */
				$out['notes'][] = sprintf( __( '%1$s.%2$s was left: %3$s', 'alphabridge-mcp' ), (string) $el['id'], (string) $name, $op->get_error_message() );
				continue;
			}
			$alone  = self::apply_ops( $block, array( $op ) );
			$before = self::read_value( $block, $own, $f );
			$after  = null === $alone ? null : self::read_value( $alone, self::own( $alone ), $f );
			$change = array(
				'element' => (string) $el['id'],
				'type'    => $type,
				'field'   => (string) $name,
				'kind'    => $f['kind'],
				'before'  => $before,
				'after'   => $after,
			);
			$keep = null === $after || ! self::predicted( $f['kind'], $before, $after, $search, $replace, $ci )
				? new WP_Error( 'ab_mcp_builder_unpredicted', __( 'the field would not read as the replacement predicts', 'alphabridge-mcp' ) )
				: AB_MCP_Builder_Fields::validate_change( $change );
			if ( is_wp_error( $keep ) ) {
				$out['skipped'] += $op['count'];
				/* translators: 1: element id, 2: field name, 3: reason */
				$out['notes'][] = sprintf( __( '%1$s.%2$s was left: %3$s', 'alphabridge-mcp' ), (string) $el['id'], (string) $name, $keep->get_error_message() );
				continue;
			}
			if ( ! self::measured( $spec, $f ) ) {
				$out['warnings'][] = sprintf(
					/* translators: 1: field name, 2: block name */
					__( 'profile not yet measured: where %2$s keeps the field %1$s comes from the block\'s code, not from a measurement on a real installation. Check the page after the change.', 'alphabridge-mcp' ),
					(string) $name,
					(string) $block['blockName']
				);
			}
			$out['notes']     = array_merge( $out['notes'], $op['notes'] );
			$out['warnings']  = array_merge( $out['warnings'], $op['warnings'] );
			$out['changes'][] = $change;
			$out['count']    += $op['count'] + $op['copies'];
			$ops[]            = $op;
		}
		if ( array() !== $ops ) {
			$new = self::apply_ops( $block, $ops );
			if ( null === $new ) {
				$out['skipped'] += $out['count'];
				$out['count']    = 0;
				$out['changes']  = array();
				$out['notes'][]  = self::split_error( (string) $el['id'] )->get_error_message();
				return $out;
			}
			$out['block'] = $new;
		}
		return $out;
	}

	/**
	 * One field with the search replaced in its text (and, in an html field,
	 * in the links, image addresses and alt texts of its inline markup).
	 *
	 * @param array  $block   Block.
	 * @param string $own     Own markup.
	 * @param array  $f       Field.
	 * @param string $type    Element type.
	 * @param string $search  Search.
	 * @param string $replace Replacement.
	 * @param bool   $ci      Case-insensitive.
	 * @return array|null { updates, attrs, count, skipped, warnings, notes }; null when the field cannot be read.
	 */
	private static function op_swap( array $block, $own, array $f, $type, $search, $replace, $ci ) {
		$kind = $f['kind'];
		$op   = array(
			'updates'  => array(),
			'attrs'    => array(),
			'count'    => 0,
			'skipped'  => 0,
			'warnings' => array(),
			'notes'    => array(),
		);
		$html = 'html' === $kind || ( 'text' === $kind && ! AB_MCP_Builder_Fields::plain_only( $kind, $type ) );
		switch ( $f['from'] ) {
			case 'attr':
				$value = self::attr_get( $block, $f['path'] );
				if ( ! is_string( $value ) ) {
					return null;
				}
				if ( 'url' === $kind ) {
					$n   = 0;
					$new = self::swap( $value, $search, $replace, $ci, $n );
					if ( $n > 0 && true !== self::url_ok( $new ) ) {
						$op['skipped'] = $n;
						return $op;
					}
				} else {
					$p   = self::markup( $value );
					$res = self::swap_tokens( $p, null, $html, $search, $replace, $ci );
					$n   = $res['count'];
					$new = $p->get_updated_html();
					$op['skipped'] = $res['skipped'];
				}
				if ( $n > 0 ) {
					$op['attrs'][] = array( $f['path'], $new );
					$op['count']   = $n;
				}
				return $op;

			case 'html_attr':
				$found = AB_MCP_Builder_Html::find( $own, $f['selector'] );
				$p     = self::markup( $own );
				if ( null === $found || ! $p->ab_seek_start_tag( $found['start'] ) ) {
					return null;
				}
				$value = $p->get_attribute( $f['attr'] );
				if ( ! is_string( $value ) ) {
					return null;
				}
				$n   = 0;
				$new = self::swap( $value, $search, $replace, $ci, $n );
				if ( 0 === $n ) {
					return $op;
				}
				if ( 'url' === $kind && true !== self::url_ok( $new ) ) {
					$op['skipped'] = $n;
					return $op;
				}
				$p->set_attribute( $f['attr'], $new );
				if ( 'src' === $f['attr'] && 'IMG' === $p->get_tag() ) {
					// A move of the site moves the files that srcset lists too.
					$set = $p->get_attribute( 'srcset' );
					if ( is_string( $set ) ) {
						$m = 0;
						$p->set_attribute( 'srcset', self::swap( $set, $search, $replace, $ci, $m ) );
					}
				}
				$op['updates'] = $p->ab_updates();
				$op['count']   = null === $op['updates'] ? 0 : $n;
				$op['skipped'] = null === $op['updates'] ? $n : 0;
				$op['updates'] = null === $op['updates'] ? array() : $op['updates'];
				return $op;

			default: // html_text, html_inner.
				$target = self::target( $own, $f['selector'] );
				if ( is_wp_error( $target ) ) {
					return null;
				}
				$p    = self::markup( $own );
				$stop = null;
				if ( 'whole' !== $target ) {
					if ( ! $p->ab_seek_start_tag( $target['start'] ) ) {
						return null;
					}
					$stop = $target['inner_end'];
				}
				$res           = self::swap_tokens( $p, $stop, $html, $search, $replace, $ci );
				$updates       = $p->ab_updates();
				$op['updates'] = null === $updates ? array() : $updates;
				$op['count']   = null === $updates ? 0 : $res['count'];
				$op['skipped'] = $res['skipped'] + ( null === $updates ? $res['count'] : 0 );
				return $op;
		}
	}

	/**
	 * Replace the search in the text nodes of a span (and, with $attrs, in
	 * the links, image addresses, alt and title texts of its tags), leaving
	 * everything inside an SVG or MathML element and the content of scripts
	 * and styles alone.
	 *
	 * @param AB_MCP_Block_Markup $p       Processor, on the start tag of the span or at the start of a fragment.
	 * @param int|null                $stop    Offset of the end tag closing the span; null for the whole fragment.
	 * @param bool                    $attrs   Whether to search attributes of inline markup.
	 * @param string                  $search  Search.
	 * @param string                  $replace Replacement.
	 * @param bool                    $ci      Case-insensitive.
	 * @return array { count, skipped }
	 */
	private static function swap_tokens( AB_MCP_Block_Markup $p, $stop, $attrs, $search, $replace, $ci ) {
		$count   = 0;
		$skipped = 0;
		$deep    = 0;
		while ( $p->next_token() ) {
			if ( null !== $stop ) {
				$at = $p->ab_token_start();
				if ( null === $at || $at >= $stop ) {
					break;
				}
			}
			$type = $p->get_token_type();
			if ( '#text' === $type ) {
				if ( $deep > 0 ) {
					continue;
				}
				$n   = 0;
				$new = self::swap( $p->get_modifiable_text(), $search, $replace, $ci, $n );
				if ( $n > 0 ) {
					if ( $p->ab_set_text_node( $new ) ) {
						$count += $n;
					} else {
						$skipped += $n;
					}
				}
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}
			$tag = (string) $p->get_tag();
			if ( 'SVG' === $tag || 'MATH' === $tag ) {
				if ( $p->is_tag_closer() ) {
					$deep = max( 0, $deep - 1 );
				} elseif ( ! $p->has_self_closing_flag() ) {
					++$deep;
				}
				continue;
			}
			if ( ! $attrs || $deep > 0 || $p->is_tag_closer() ) {
				continue;
			}
			foreach ( array_merge( self::URL_ATTRS, self::TEXT_ATTRS ) as $attr ) {
				$value = $p->get_attribute( $attr );
				if ( ! is_string( $value ) ) {
					continue;
				}
				$n   = 0;
				$new = self::swap( $value, $search, $replace, $ci, $n );
				if ( 0 === $n ) {
					continue;
				}
				if ( in_array( $attr, self::URL_ATTRS, true ) && true !== self::url_ok( $new ) ) {
					$skipped += $n;
					continue;
				}
				if ( $p->set_attribute( $attr, $new ) ) {
					$count += $n;
				} else {
					$skipped += $n;
				}
			}
		}
		return array(
			'count'   => $count,
			'skipped' => $skipped,
		);
	}

	/* ----------------------------------------------------- the whole new page */

	/**
	 * Serialize the new page and check it before anything is written: it
	 * differs, keeps every element, and the reader finds every changed field
	 * as expected. Pagelayer's tree is worked out here too.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $before  Blocks as parsed.
	 * @param array   $after   Blocks as changed.
	 * @param array   $changes Changes.
	 * @return array|WP_Error { blocks: { base, content, pagelayer }, warnings, notes }
	 */
	private function finish( $post, array $before, array $after, array $changes ) {
		$old     = (string) $post->post_content;
		$content = serialize_blocks( $after );
		if ( $content === $old ) {
			return new WP_Error( 'ab_mcp_builder_unchanged', __( 'The element already shows these values; there is nothing to change. Check them with wp_get_builder_layout.', 'alphabridge-mcp' ) );
		}
		$check = $this->dry_readback( $old, $content, $changes );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$out = array(
			'blocks'   => array(
				'base'      => hash( 'sha256', $old ),
				'content'   => $content,
				'pagelayer' => null,
			),
			'warnings' => array(),
			'notes'    => array(),
		);
		if ( false !== strpos( $old, '<!-- wp:pagelayer/' ) ) {
			$meta = get_post_meta( (int) $post->ID, self::PAGELAYER_META, true );
			if ( is_array( $meta ) ) {
				$was = self::pl_tree( $before );
				$now = self::pl_tree( $after );
				if ( null !== $was && null !== $now && self::pl_same( $meta, $was ) ) {
					$merged = self::pl_merge( $meta, $now );
					if ( self::pl_same( $merged, $now ) ) {
						$out['blocks']['pagelayer'] = $merged;
						$out['notes'][]             = __( 'Pagelayer\'s own copy of the page (pagelayer-data, which its AI abilities write the page from) was changed with it.', 'alphabridge-mcp' );
						return $out;
					}
				}
				$out['warnings'][] = __( 'Pagelayer\'s own copy of this page (pagelayer-data, which only its AI abilities use) no longer matches the page, as after any save in Pagelayer\'s editor, and was left as it is. The page shows the change; if one of Pagelayer\'s abilities changes this page later, it writes the page from that copy and this change is lost, as earlier changes in the editor would be.', 'alphabridge-mcp' );
			}
		}
		return $out;
	}

	/**
	 * The read-back before the write: the free reader reads the new page with
	 * the same elements, every changed field as expected, and every other
	 * field, lock and type as before. A value that brings markup the
	 * profile's selectors would find (a link with the class a field is read
	 * by, say) would otherwise change another field unasked.
	 *
	 * @param string $old     Content before.
	 * @param string $content Content after.
	 * @param array  $changes Changes.
	 * @return true|WP_Error
	 */
	private function dry_readback( $old, $content, array $changes ) {
		$ids = static function ( array $outline ) {
			$out = array();
			foreach ( $outline as $el ) {
				$out[ (string) $el['id'] ] = $el;
			}
			return $out;
		};
		$was     = $ids( AB_MCP_Block_Reader::outline( $old, $this->outline_opts() ) );
		$now     = $ids( AB_MCP_Block_Reader::outline( $content, $this->outline_opts() ) );
		$changed = array();
		$bad     = array_keys( $was ) !== array_keys( $now );
		foreach ( $changes as $c ) {
			$changed[ $c['element'] ][ $c['field'] ] = true;
			if ( $bad ) {
				break;
			}
			$field  = isset( $now[ $c['element'] ]['fields'][ $c['field'] ] ) ? $now[ $c['element'] ]['fields'][ $c['field'] ] : null;
			$expect = array_key_exists( 'expect', $c ) ? $c['expect'] : $c['after'];
			$have   = null === $field ? '' : $field['value'];
			if ( null !== $field && ! empty( $field['truncated'] ) ) {
				$bad = substr( self::norm( $c['kind'], $have ), 0, 200 ) !== substr( self::norm( $c['kind'], $expect ), 0, 200 );
			} else {
				$bad = self::norm( $c['kind'], $have ) !== self::norm( $c['kind'], $expect );
			}
		}
		if ( $bad ) {
			return new WP_Error(
				'ab_mcp_builder_not_written',
				__( 'AlphaBridge would not find the new value where it writes it on this page (its markup is not in the form the block\'s profile describes), so nothing was changed. Change it in the block editor, or with wp_update_post (keep the block markup as it is).', 'alphabridge-mcp' )
			);
		}
		foreach ( $was as $id => $el ) {
			$then = isset( $el['fields'] ) ? $el['fields'] : array();
			$next = isset( $now[ $id ]['fields'] ) ? $now[ $id ]['fields'] : array();
			foreach ( array_keys( $then + $next ) as $name ) {
				if ( isset( $changed[ $id ][ $name ] ) ) {
					continue;
				}
				if ( ! isset( $then[ $name ], $next[ $name ] ) || $then[ $name ] !== $next[ $name ] ) {
					$bad = true;
				}
			}
			foreach ( array( 'type', 'locked', 'reason' ) as $key ) {
				if ( ( isset( $el[ $key ] ) ? $el[ $key ] : null ) !== ( isset( $now[ $id ][ $key ] ) ? $now[ $id ][ $key ] : null ) ) {
					$bad = true;
				}
			}
		}
		if ( $bad ) {
			return new WP_Error(
				'ab_mcp_builder_side_effect',
				__( 'Written this way, the change would also change other parts of the page as AlphaBridge reads them (markup in the new value that another field or element is read by, such as a link with the element\'s class). Nothing was changed. Send the value without that markup, or make the change in the block editor.', 'alphabridge-mcp' )
			);
		}
		return true;
	}

	/**
	 * For an account WordPress filters with kses: whether its save would
	 * change the new page beyond what changes anyway (white space in tags and
	 * "/>" written as " />", measured for core, Kadence and Stackable).
	 *
	 * @param string $content New post_content.
	 * @return true|WP_Error
	 */
	private static function save_filter_guard( $content ) {
		if ( false === has_filter( 'content_save_pre', 'wp_filter_post_kses' ) || ! function_exists( 'wp_kses_post' ) ) {
			return true;
		}
		if ( self::kses_key( wp_kses_post( $content ) ) === self::kses_key( $content ) ) {
			return true;
		}
		return new WP_Error(
			'ab_mcp_builder_kses_page',
			__( 'Your account cannot post unfiltered HTML, so WordPress filters the whole page when it saves it — and on this page that would change more than this field: it would remove or rewrite markup such as icons, inline styles, image sets or code. Nothing was changed. Ask an administrator of a single site (on a network, a super admin) to make this change; the field itself is checked the same way for every account.', 'alphabridge-mcp' )
		);
	}

	/**
	 * Markup with the differences kses makes on every page taken out.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function kses_key( $html ) {
		return (string) preg_replace( array( '~\s+~', '~\s*/>~', '~\s+>~' ), array( ' ', '/>', '>' ), (string) $html );
	}

	/* ---------------------------------------------------------------- copies */

	/**
	 * Keep the copies of a field ("also") in step: a copy that held the old
	 * value gets the new one. A copy that held something else already is
	 * stale — the block editor then finds the block invalid, since its save()
	 * builds the markup from that copy. A value set explicitly (set_fields,
	 * $explicit) goes into every copy, the stale one too, so the block and
	 * its markup agree again; a replace() leaves a stale copy, which it was
	 * not asked about. Either way the notes name it. Only copies in block
	 * attributes exist in the profiles; one elsewhere is refused rather than
	 * left stale.
	 *
	 * @param array  $block    Block.
	 * @param string $own      Own markup.
	 * @param array  $f        Field.
	 * @param array  $op       The field's operation.
	 * @param bool   $explicit The value was set (set_fields), not replaced in.
	 * @return array|WP_Error The operation with the copies' attribute values,
	 *                        and 'copies': how many copies changed.
	 */
	private static function with_copies( array $block, $own, array $f, array $op, $explicit = false ) {
		$op['copies'] = 0;
		if ( array() === $f['also'] ) {
			return $op;
		}
		$alone = self::apply_ops( $block, array( $op ) );
		if ( null === $alone ) {
			return self::split_error( '' );
		}
		$old = self::read_raw( $block, $own, $f );
		$new = self::read_raw( $alone, self::own( $alone ), $f );
		foreach ( $f['also'] as $copy ) {
			if ( 'attr' !== $copy['from'] ) {
				return new WP_Error( 'ab_mcp_builder_copy', __( 'The block keeps a copy of this field in its markup, which this version does not keep in step. Change it in the block editor.', 'alphabridge-mcp' ) );
			}
			$have = self::attr_get( $block, $copy['path'] );
			if ( null === $have || ! is_scalar( $have ) || is_bool( $have ) ) {
				continue;
			}
			if ( null === $old || ! self::same_text( $have, $old ) ) {
				if ( ! $explicit ) {
					/* translators: %s: attribute path */
					$op['notes'][] = sprintf( __( 'The copy in the block attribute %s held another value than the page shows and was left as it is.', 'alphabridge-mcp' ), $copy['path'] );
					continue;
				}
				/* translators: %s: attribute path */
				$op['notes'][] = sprintf( __( 'The copy in the block attribute %s held another value than the page shows (the block editor would have found the block invalid); it was set to the new value too, so the block and the page agree again.', 'alphabridge-mcp' ), $copy['path'] );
			}
			$op['attrs'][] = array( $copy['path'], (string) $new );
			++$op['copies'];
		}
		return $op;
	}

	/* --------------------------------------------------------------- applying */

	/**
	 * A block with the operations applied: markup changes into the pieces of
	 * its own markup (never across an inner block), attribute values set.
	 *
	 * @param array $block Block.
	 * @param array $ops   Operations { updates, attrs }.
	 * @return array|null Null when a change would cross an inner block or two overlap.
	 */
	private static function apply_ops( array $block, array $ops ) {
		$updates = array();
		foreach ( $ops as $op ) {
			foreach ( $op['updates'] as $u ) {
				$updates[] = $u;
			}
			foreach ( $op['attrs'] as $set ) {
				$block['attrs'] = self::attr_set( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(), $set[0], $set[1] );
			}
		}
		if ( array() === $updates ) {
			return $block;
		}
		usort(
			$updates,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
			}
		);
		$end = -1;
		foreach ( $updates as $u ) {
			if ( $u[0] < $end ) {
				return null;
			}
			$end = $u[0] + $u[1];
		}
		$pieces = array();
		$pos    = 0;
		foreach ( $block['innerContent'] as $i => $chunk ) {
			if ( is_string( $chunk ) ) {
				$pieces[ $i ] = array( $pos, $pos + strlen( $chunk ) );
				$pos         += strlen( $chunk );
			}
		}
		$by_piece = array();
		foreach ( $updates as $u ) {
			$hit = null;
			foreach ( $pieces as $i => $span ) {
				if ( $u[0] >= $span[0] && $u[0] + $u[1] <= $span[1] ) {
					$hit = $i;
					break;
				}
			}
			if ( null === $hit ) {
				return null;
			}
			$by_piece[ $hit ][] = array( $u[0] - $pieces[ $hit ][0], $u[1], $u[2] );
		}
		foreach ( $by_piece as $i => $list ) {
			$chunk = (string) $block['innerContent'][ $i ];
			foreach ( array_reverse( $list ) as $u ) {
				$chunk = substr_replace( $chunk, $u[2], $u[0], $u[1] );
			}
			$block['innerContent'][ $i ] = $chunk;
		}
		$block['innerHTML'] = self::own( $block );
		return $block;
	}

	/* ------------------------------------------------------------ the blocks */

	/**
	 * The block profile entry of a block name, with the attribute that holds
	 * its library id — as the reader looks it up: the first profile that names
	 * the block, else the profile of its namespace.
	 *
	 * @param string $name Block name.
	 * @return array { spec: array|null, builder: string, id_attr: string }
	 */
	private static function entry( $name ) {
		if ( null === self::$index ) {
			$blocks = array();
			$spaces = array();
			foreach ( AB_MCP_Block_Reader::profiles() as $profile ) {
				foreach ( $profile['blocks'] as $block => $spec ) {
					if ( ! isset( $blocks[ $block ] ) ) {
						$blocks[ $block ] = array( $spec, $profile );
					}
				}
				foreach ( $profile['namespaces'] as $prefix ) {
					if ( ! isset( $spaces[ $prefix ] ) ) {
						$spaces[ $prefix ] = $profile;
					}
				}
			}
			self::$index = array( $blocks, $spaces );
		}
		if ( isset( self::$index[0][ $name ] ) ) {
			list( $spec, $profile ) = self::$index[0][ $name ];
			return array(
				'spec'    => $spec,
				'builder' => (string) $profile['builder'],
				'id_attr' => '' !== $spec['id_attr'] ? $spec['id_attr'] : $profile['id_attr'],
			);
		}
		foreach ( self::$index[1] as $prefix => $profile ) {
			if ( 0 === strpos( (string) $name, (string) $prefix ) ) {
				return array(
					'spec'    => null,
					'builder' => (string) $profile['builder'],
					'id_attr' => $profile['id_attr'],
				);
			}
		}
		return array(
			'spec'    => null,
			'builder' => '',
			'id_attr' => '',
		);
	}

	/**
	 * Path of the block an outline id names: the path itself, or the one
	 * block whose library id it is.
	 *
	 * @param array  $blocks Blocks.
	 * @param string $id     Element id.
	 * @return int[]|null
	 */
	private static function locate( array $blocks, $id ) {
		if ( 1 === preg_match( self::PATH, (string) $id, $m ) ) {
			$path  = array_map( 'intval', explode( '.', $m[1] ) );
			$list  = $blocks;
			$block = null;
			foreach ( $path as $i ) {
				if ( ! isset( $list[ $i ] ) || ! is_array( $list[ $i ] ) ) {
					return null;
				}
				$block = $list[ $i ];
				$list  = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			}
			return null !== $block && ! empty( $block['blockName'] ) ? $path : null;
		}
		$hits = array();
		self::find_id( $blocks, array(), (string) $id, $hits, 0 );
		return 1 === count( $hits ) ? $hits[0] : null;
	}

	/**
	 * Collect the paths of blocks whose library id is $id.
	 *
	 * @param array  $blocks Blocks.
	 * @param int[]  $path   Path of this level.
	 * @param string $id     Id.
	 * @param array  $hits   Paths (by reference).
	 * @param int    $depth  Depth.
	 */
	private static function find_id( array $blocks, array $path, $id, array &$hits, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$attr = self::entry( (string) $block['blockName'] )['id_attr'];
			if ( '' !== $attr && isset( $block['attrs'][ $attr ] ) && is_scalar( $block['attrs'][ $attr ] ) && (string) $block['attrs'][ $attr ] === $id ) {
				$hits[] = array_merge( $path, array( (int) $i ) );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::find_id( $block['innerBlocks'], array_merge( $path, array( (int) $i ) ), $id, $hits, $depth + 1 );
			}
		}
	}

	/**
	 * The block at a path.
	 *
	 * @param array $blocks Blocks.
	 * @param int[] $path   Path.
	 * @return array
	 */
	private static function block_at( array $blocks, array $path ) {
		$i = array_shift( $path );
		return array() === $path ? $blocks[ $i ] : self::block_at( $blocks[ $i ]['innerBlocks'], $path );
	}

	/**
	 * The blocks with the block at a path replaced.
	 *
	 * @param array $blocks Blocks.
	 * @param int[] $path   Path.
	 * @param array $block  New block.
	 * @return array
	 */
	private static function put_block( array $blocks, array $path, array $block ) {
		$i = array_shift( $path );
		if ( array() === $path ) {
			$blocks[ $i ] = $block;
		} else {
			$blocks[ $i ]['innerBlocks'] = self::put_block( $blocks[ $i ]['innerBlocks'], $path, $block );
		}
		return $blocks;
	}

	/**
	 * A block's own markup: its innerContent strings, without inner blocks.
	 *
	 * @param array $block Block.
	 * @return string
	 */
	private static function own( array $block ) {
		$own = '';
		foreach ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array() as $chunk ) {
			if ( is_string( $chunk ) ) {
				$own .= $chunk;
			}
		}
		return $own;
	}

	/**
	 * The element a markup field names: the selector's element with its
	 * offsets; 'whole' for a field without selector on markup that is plain
	 * text. Without a selector the field is the block's whole markup, which
	 * is written inside its one element (Pagelayer's <h1>…</h1>, <p>…</p>).
	 *
	 * @param string $own      Own markup.
	 * @param string $selector Selector.
	 * @return array|string|WP_Error
	 */
	private static function target( $own, $selector ) {
		$found = AB_MCP_Builder_Html::find( $own, $selector );
		if ( '' === (string) $selector ) {
			if ( null === $found ) {
				return false === strpos( $own, '<' ) ? 'whole' : self::markup_error();
			}
			if ( '' !== trim( substr( $own, 0, $found['start'] ) ) || '' !== trim( (string) substr( $own, $found['end'] ) ) ) {
				return new WP_Error( 'ab_mcp_builder_several', __( 'This text consists of several paragraphs or parts, and the outline shows them as one: writing it would merge them. Change the words with wp_search_replace, or edit the element in the block editor.', 'alphabridge-mcp' ) );
			}
		}
		// A void element has nothing inside to write, and an element that is
		// never closed runs to the end of the markup: neither is written.
		if ( null === $found || $found['end'] <= $found['inner_end'] || '</' !== substr( $own, $found['inner_end'], 2 ) ) {
			return self::markup_error();
		}
		return $found;
	}

	/**
	 * The raw value a field source points to (as the free reader reads it).
	 *
	 * @param array  $block Block.
	 * @param string $own   Own markup.
	 * @param array  $src   Field or copy.
	 * @return string|int|float|null
	 */
	private static function read_raw( array $block, $own, array $src ) {
		switch ( $src['from'] ) {
			case 'html_text':
			case 'html_inner':
				return '' === $src['selector'] ? $own : AB_MCP_Builder_Html::inner( $own, $src['selector'] );
			case 'html_attr':
				return AB_MCP_Builder_Html::element_attr( $own, $src['selector'], $src['attr'] );
			case 'attr':
				$value = self::attr_get( $block, $src['path'] );
				return is_bool( $value ) || ! is_scalar( $value ) ? null : $value;
		}
		return null;
	}

	/**
	 * A field's value in the form the outline shows it (as the free reader
	 * reads it, AB_MCP_Block_Reader).
	 *
	 * @param array  $block Block.
	 * @param string $own   Own markup.
	 * @param array  $f     Field.
	 * @return string|int|null
	 */
	private static function read_value( array $block, $own, array $f ) {
		$raw = self::read_raw( $block, $own, $f );
		if ( null === $raw ) {
			return null;
		}
		switch ( $f['kind'] ) {
			case 'text':
			case 'heading':
				return AB_MCP_Builders::field_text( 'html_attr' === $f['from'] ? htmlspecialchars( (string) $raw, ENT_QUOTES, 'UTF-8' ) : (string) $raw );
			case 'html':
				return AB_MCP_Builder_Html::simple( (string) $raw );
			case 'image':
				$value = is_int( $raw ) || ( is_string( $raw ) && 1 === preg_match( '~^[0-9]+\z~', $raw ) ) || ( is_float( $raw ) && floor( $raw ) === $raw ) ? (int) $raw : trim( (string) $raw );
				return is_string( $value ) ? AB_MCP_Builder_Html::safe_url( $value, true ) : $value;
			default:
				return AB_MCP_Builder_Html::safe_url( trim( (string) $raw ), 'html_attr' === $f['from'] && 'src' === $f['attr'] );
		}
	}

	/**
	 * A block attribute by its dotted path.
	 *
	 * @param array  $block Block.
	 * @param string $path  Path.
	 * @return mixed|null
	 */
	private static function attr_get( array $block, $path ) {
		$value = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		foreach ( explode( '.', (string) $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	/**
	 * Attributes with one value set by its dotted path.
	 *
	 * @param array  $attrs Attributes.
	 * @param string $path  Path.
	 * @param mixed  $value Value.
	 * @return array
	 */
	private static function attr_set( array $attrs, $path, $value ) {
		$segments = explode( '.', (string) $path );
		$key      = array_shift( $segments );
		if ( array() === $segments ) {
			$attrs[ $key ] = $value;
			return $attrs;
		}
		$attrs[ $key ] = self::attr_set( isset( $attrs[ $key ] ) && is_array( $attrs[ $key ] ) ? $attrs[ $key ] : array(), implode( '.', $segments ), $value );
		return $attrs;
	}

	/**
	 * Whether the profile entry or field was measured on a real installation.
	 *
	 * @param array $spec Profile entry.
	 * @param array $f    Field.
	 * @return bool
	 */
	private static function measured( array $spec, array $f ) {
		return null === $f['verified'] ? (bool) $spec['verified'] : (bool) $f['verified'];
	}

	/* ----------------------------------------------------------- the values */

	/**
	 * HTML as stored: what kses let through, with a bare "&" written as
	 * "&amp;", as the block editor writes it.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function html_value( $html ) {
		return (string) preg_replace( '~&(?!(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#[xX][0-9A-Fa-f]+);)~', '&amp;', (string) $html );
	}

	/**
	 * The text of an attribute (alt, title) from a value that may hold
	 * character references; markup cannot live in an attribute.
	 *
	 * @param string $value Value.
	 * @return string|WP_Error
	 */
	private static function attr_text( $value ) {
		if ( 1 === preg_match( '~<[A-Za-z/!?]~', (string) $value ) ) {
			return new WP_Error( 'ab_mcp_builder_plain_text', __( 'This field is an attribute of the element (such as an image\'s alt text) and takes plain text only, without HTML tags. Send the words without markup.', 'alphabridge-mcp' ) );
		}
		return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Refused when WordPress would store a link differently than asked
	 * (set_attribute() runs esc_url() on href and src).
	 *
	 * @param string $url Link.
	 * @return true|WP_Error
	 */
	private static function url_kept( $url ) {
		if ( '' === $url ) {
			return true;
		}
		$stored = html_entity_decode( (string) esc_url( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $stored === $url ) {
			return true;
		}
		return new WP_Error(
			'ab_mcp_builder_url_changed',
			sprintf(
				/* translators: 1: link asked for, 2: link as WordPress would store it */
				__( 'WordPress would store the link %1$s as «%2$s». Nothing was changed. Encode the characters it drops (e.g. { as %%7B), or check the address.', 'alphabridge-mcp' ),
				$url,
				$stored
			)
		);
	}

	/**
	 * Whether a link a replace produced may be stored: the field rules for
	 * links, and stored as it is.
	 *
	 * @param string $url Link.
	 * @return bool
	 */
	private static function url_ok( $url ) {
		$checked = AB_MCP_Builder_Fields::check( 'url', $url );
		return ! is_wp_error( $checked ) && (string) $checked['value'] === (string) $url && true === self::url_kept( $url );
	}

	/**
	 * Whether a copy holds the same value as the field: the same characters
	 * once character references are read (the block editor keeps rich text
	 * HTML-escaped in an attribute, a script may have stored it plain).
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 * @return bool
	 */
	private static function same_text( $a, $b ) {
		return trim( html_entity_decode( (string) $a, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === trim( html_entity_decode( (string) $b, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Replace, counting.
	 *
	 * @param string $text    Text.
	 * @param string $search  Search.
	 * @param string $replace Replacement.
	 * @param bool   $ci      Case-insensitive.
	 * @param int    $n       Matches (by reference).
	 * @return string
	 */
	private static function swap( $text, $search, $replace, $ci, &$n ) {
		$text = (string) $text;
		if ( $ci ) {
			$out = preg_replace( '~' . preg_quote( (string) $search, '~' ) . '~iu', str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), (string) $replace ), $text, -1, $n );
			if ( null === $out ) {
				$n = 0;
				return $text;
			}
			return $out;
		}
		$n = substr_count( $text, (string) $search );
		return 0 === $n ? $text : str_replace( (string) $search, (string) $replace, $text );
	}

	/**
	 * Whether a replaced field reads as replacing the text the outline showed
	 * would read: the visible words for text, the address for a link.
	 *
	 * @param string $kind    Kind.
	 * @param mixed  $before  Value before (outline form).
	 * @param mixed  $after   Value after (outline form).
	 * @param string $search  Search.
	 * @param string $replace Replacement.
	 * @param bool   $ci      Case-insensitive.
	 * @return bool
	 */
	private static function predicted( $kind, $before, $after, $search, $replace, $ci ) {
		$n = 0;
		if ( 'url' === $kind ) {
			return self::swap( (string) $before, $search, $replace, $ci, $n ) === (string) $after;
		}
		if ( 'html' === $kind ) {
			$before = AB_MCP_Builders::field_text( (string) $before );
			$after  = AB_MCP_Builders::field_text( (string) $after );
		}
		return self::squash( self::swap( (string) $before, $search, $replace, $ci, $n ) ) === self::squash( (string) $after );
	}

	/**
	 * How often the search stands in the stored page, in every form a
	 * block page stores text in: as typed, as HTML text, as block attribute
	 * JSON, and as HTML text inside block attribute JSON.
	 *
	 * @param string $content post_content.
	 * @param string $search  Search.
	 * @param bool   $ci      Case-insensitive.
	 * @return int
	 */
	private static function raw_count( $content, $search, $ci ) {
		$html     = AB_MCP_Block_Markup::ab_text_html( $search );
		$variants = array_unique( array( $search, $html, self::attr_json( $search ), self::attr_json( $html ) ) );
		$count    = 0;
		foreach ( $variants as $variant ) {
			if ( '' === $variant ) {
				continue;
			}
			$count += $ci ? (int) preg_match_all( '~' . preg_quote( $variant, '~' ) . '~iu', $content ) : substr_count( $content, $variant );
		}
		return $count;
	}

	/**
	 * A text as it stands inside a block attribute (serialize_block_attributes()).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function attr_json( $text ) {
		return (string) substr( serialize_block_attributes( array( (string) $text ) ), 2, -2 );
	}

	/**
	 * White space collapsed, as the outline shows text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function squash( $text ) {
		$out = preg_replace( '~[\s\x{00A0}]+~u', ' ', (string) $text );
		return trim( null === $out ? (string) $text : $out );
	}

	/* -------------------------------------------------------------- Pagelayer */

	/**
	 * The page in the form of Pagelayer's tree: nodes { tag, attrs, content },
	 * content the child nodes or the block's markup trimmed. Null when the
	 * page holds anything the tree has no place for.
	 *
	 * @param array $blocks Blocks.
	 * @return array|null
	 */
	private static function pl_tree( array $blocks ) {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				if ( '' !== trim( self::own( $block ) ) ) {
					return null;
				}
				continue;
			}
			if ( 0 !== strpos( (string) $block['blockName'], 'pagelayer/' ) ) {
				return null;
			}
			$node = array(
				'tag'   => substr( (string) $block['blockName'], 10 ),
				'attrs' => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
			);
			$own  = trim( self::own( $block ) );
			if ( ! empty( $block['innerBlocks'] ) ) {
				$kids = self::pl_tree( $block['innerBlocks'] );
				if ( null === $kids || '' !== $own ) {
					return null;
				}
				$node['content'] = $kids;
			} elseif ( '' !== $own ) {
				$node['content'] = $own;
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * Whether two trees hold the same page: tags, attributes (values as
	 * text, in any order) and contents (trimmed).
	 *
	 * @param mixed $a Tree.
	 * @param mixed $b Tree.
	 * @return bool
	 */
	private static function pl_same( $a, $b ) {
		if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) || array_keys( $a ) !== array_keys( $b ) ) {
			return false;
		}
		foreach ( $a as $i => $x ) {
			$y = $b[ $i ];
			if ( ! is_array( $x ) || ! is_array( $y ) || ! isset( $x['tag'], $y['tag'] ) || (string) $x['tag'] !== (string) $y['tag'] ) {
				return false;
			}
			if ( self::pl_key( isset( $x['attrs'] ) ? $x['attrs'] : array() ) !== self::pl_key( isset( $y['attrs'] ) ? $y['attrs'] : array() ) ) {
				return false;
			}
			$cx = isset( $x['content'] ) ? $x['content'] : '';
			$cy = isset( $y['content'] ) ? $y['content'] : '';
			if ( is_array( $cx ) || is_array( $cy ) ) {
				if ( ! is_array( $cx ) || ! is_array( $cy ) || ! self::pl_same( $cx, $cy ) ) {
					return false;
				}
			} elseif ( ! is_scalar( $cx ) || ! is_scalar( $cy ) || trim( (string) $cx ) !== trim( (string) $cy ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Attributes as one comparable string: keys sorted, values as text.
	 *
	 * @param mixed $attrs Attributes.
	 * @return string
	 */
	private static function pl_key( $attrs ) {
		$norm = static function ( $v ) use ( &$norm ) {
			if ( is_array( $v ) ) {
				ksort( $v, SORT_STRING );
				return array_map( $norm, $v );
			}
			return is_bool( $v ) ? ( $v ? '1' : '' ) : ( is_scalar( $v ) ? (string) $v : '' );
		};
		return (string) wp_json_encode( $norm( is_array( $attrs ) ? $attrs : array() ) );
	}

	/**
	 * Pagelayer's tree with the values of the new page put in where they
	 * differ; everything else stays as Pagelayer wrote it (order, types).
	 *
	 * @param array $meta Tree as stored.
	 * @param array $now  Tree of the new page (same shape).
	 * @return array
	 */
	private static function pl_merge( array $meta, array $now ) {
		foreach ( $meta as $i => $node ) {
			if ( ! isset( $now[ $i ] ) || ! is_array( $node ) ) {
				continue;
			}
			$new   = $now[ $i ];
			$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
			foreach ( $new['attrs'] as $key => $value ) {
				if ( ! array_key_exists( $key, $attrs ) || self::pl_key( array( $attrs[ $key ] ) ) !== self::pl_key( array( $value ) ) ) {
					$attrs[ $key ] = $value;
				}
			}
			if ( isset( $node['attrs'] ) || array() !== $attrs ) {
				$meta[ $i ]['attrs'] = $attrs;
			}
			$content = isset( $new['content'] ) ? $new['content'] : null;
			if ( is_array( $content ) ) {
				$meta[ $i ]['content'] = self::pl_merge( isset( $node['content'] ) && is_array( $node['content'] ) ? $node['content'] : array(), $content );
			} elseif ( null !== $content && ( ! isset( $node['content'] ) || ! is_scalar( $node['content'] ) || trim( (string) $node['content'] ) !== $content ) ) {
				$meta[ $i ]['content'] = $content;
			}
		}
		return $meta;
	}

	/* ------------------------------------------------------------------ misc */

	/**
	 * Load the HTML API subclass, once WordPress' tag processor is there.
	 *
	 * @return bool
	 */
	private static function load_markup() {
		if ( class_exists( 'AB_MCP_Block_Markup', false ) ) {
			return true;
		}
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ! class_exists( 'WP_HTML_Text_Replacement' ) ) {
			return false;
		}
		require_once dirname( __DIR__ ) . '/class-block-markup.php';
		return class_exists( 'AB_MCP_Block_Markup', false );
	}

	/**
	 * A processor on markup.
	 *
	 * @param string $html Markup.
	 * @return AB_MCP_Block_Markup
	 */
	private static function markup( $html ) {
		return new AB_MCP_Block_Markup( (string) $html );
	}

	/**
	 * Whether markup comes back byte for byte from parse_blocks() and
	 * serialize_blocks().
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	private static function round_trips( $content ) {
		$key = hash( 'sha256', (string) $content );
		if ( ! isset( self::$roundtrip[ $key ] ) ) {
			if ( count( self::$roundtrip ) > 64 ) {
				self::$roundtrip = array();
			}
			self::$roundtrip[ $key ] = serialize_blocks( parse_blocks( (string) $content ) ) === (string) $content;
		}
		return self::$roundtrip[ $key ];
	}

	/**
	 * The refusal for a block that is not where the outline put it.
	 *
	 * @param string $id Element id.
	 * @return WP_Error
	 */
	private static function not_found( $id ) {
		return new WP_Error(
			'ab_mcp_builder_no_element',
			/* translators: %s: element id */
			sprintf( __( 'Element %s was not found in the page\'s blocks as the outline lists it. Read the page again with wp_get_builder_layout and use the ids from there.', 'alphabridge-mcp' ), $id )
		);
	}

	/**
	 * The refusal for markup that is not in the form the profile reads.
	 *
	 * @return WP_Error
	 */
	private static function markup_error() {
		return new WP_Error(
			'ab_mcp_builder_markup',
			__( 'The markup of this element is not in the form its block profile describes, so AlphaBridge only reads it. Change it in the block editor, or with wp_update_post (keep the block markup as it is).', 'alphabridge-mcp' )
		);
	}

	/**
	 * The refusal for a change that would run across an inner block.
	 *
	 * @param string $id Element id.
	 * @return WP_Error
	 */
	private static function split_error( $id ) {
		return new WP_Error(
			'ab_mcp_builder_split',
			/* translators: %s: element id */
			sprintf( __( 'The field of element %s runs across blocks nested inside it, so changing it would move them. Change it in the block editor.', 'alphabridge-mcp' ), '' !== $id ? $id : '?' )
		);
	}

	/**
	 * A refusal about a field, naming the element.
	 *
	 * @param WP_Error $error Refusal.
	 * @param string   $id    Element id.
	 * @return WP_Error
	 */
	private static function with_element( WP_Error $error, $id ) {
		/* translators: 1: element id, 2: message */
		return new WP_Error( $error->get_error_code(), sprintf( __( 'Element %1$s: %2$s', 'alphabridge-mcp' ), $id, $error->get_error_message() ), $error->get_error_data() );
	}
}

AB_MCP_Builder_Writers::register_class( 'AB_MCP_Builder_Writer_Blocks' );
