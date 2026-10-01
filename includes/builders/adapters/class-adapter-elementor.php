<?php
/**
 * Adapter for pages built with Elementor (storage B): the page is the JSON
 * tree in the meta key _elementor_data; post_content is only a text copy
 * Elementor writes for search and does not show while it is active.
 *
 * Two formats live in the same tree and are read side by side:
 * - V3: elType section/column/container/widget, widgetType, and plain
 *   settings ("title": "Hello", "link": { "url": … }).
 * - V4 (atomic): elType e-flexbox/e-div-block …, widgetType e-heading …,
 *   every setting wrapped as { "$$type": …, "value": … }, so the data says
 *   itself which value is text, a link or an image.
 *
 * Which V3 settings are fields:
 * - With Elementor loaded (every real site where it is active): the controls
 *   the widget registers. Controls of type text, textarea, wysiwyg, url and
 *   media on the panel's content tab are fields, also inside repeaters (the
 *   style and advanced tabs hold styling and code); a setting that is not
 *   stored is read from the control's default, as Elementor renders it. An element
 *   whose type Elementor does not know is locked: Elementor neither shows it
 *   nor keeps it when the page is saved.
 * - Without Elementor loaded: the widget table below (WIDGETS), taken from
 *   Elementor's own code; a widget not in it is locked as unknown.
 * Either way a field NAME on the deny list (denied_name()) is never read —
 * an add-on may register an attribute or a code snippet as a plain text
 * control, and render it raw.
 *
 * Locked (listed without content, children skipped): the html and shortcode
 * widgets, global parts (Elementor Pro's global widget and template widget,
 * V4 components), forms, WordPress widgets placed in Elementor, unregistered
 * widgets. A field filled by a dynamic tag (__dynamic__, or $$type
 * "dynamic") is left out and named in the element's note.
 *
 * Element ids are Elementor's own ids where they are usable and unique on the
 * page; otherwise a path "e" + indices ("e0.1.2", positions in the stored
 * arrays), valid only together with layout_hash.
 *
 * Sources, abbreviated as in signatures.php:
 * - [M ergebnis-1/-2/-3/-4] the measurement folder of the plan (Elementor
 *   4.3.3, WordPress 7.1.2, 30.09.2026).
 * - [SVN elementor@4.3.3 file:line] plugins.svn.wordpress.org/elementor/
 *   tags/4.3.3/, read 01.10.2026.
 * - [Elementor #10725] github.com/elementor/elementor/issues/10725 (error log
 *   of elementor-pro/modules/global-widget/widgets/global-widget.php reading
 *   "templateID"), [WPML] wpml.org/errata/elementor-pro-elementor-loop-
 *   template-button-is-not-translated/ (widget types "global" and "template"
 *   carry template ids), read 01.10.2026 — third parties, used only for locks.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Adapter_Elementor
 */
class AB_MCP_Builder_Adapter_Elementor extends AB_MCP_Builder_Adapter {

	/** Meta key of the page tree [SVN elementor@4.3.3 core/base/document.php:44]. */
	const DATA_KEY = '_elementor_data';

	/** Settings key of dynamic tags [SVN elementor@4.3.3 core/dynamic-tags/manager.php:22]. */
	const DYNAMIC_KEY = '__dynamic__';

	/**
	 * Control types that hold visible content, and the kind each becomes
	 * [SVN elementor@4.3.3 includes/managers/controls.php:54-199].
	 */
	const CONTROL_KINDS = array(
		'text'     => 'text',
		'textarea' => 'text',
		'wysiwyg'  => 'html',
		'url'      => 'url',
		'media'    => 'image',
	);

	/** Repeater control type: a list of items with fields of their own. */
	const REPEATER = 'repeater';

	/**
	 * The panel tab of content controls. Every control carries its tab
	 * (default "content") [SVN elementor@4.3.3 includes/managers/controls.php:
	 * 817-824]; the style and advanced tabs hold styling, ids, classes, custom
	 * CSS and attributes, and layout values typed as text ("_grid_column_custom")
	 * or media ("_mask_image") [SVN elementor@4.3.3 includes/widgets/
	 * common-base.php:413, :457, :781, :799, :1113], so only this tab is read.
	 */
	const CONTENT_TAB = 'content';

	/**
	 * Element types that only hold other elements, known without Elementor
	 * loaded: [SVN elementor@4.3.3 includes/managers/elements.php:264-273]
	 * section, column, container; [SVN elementor@4.3.3 modules/atomic-widgets/
	 * elements/flexbox/flexbox.php:36, div-block/div-block.php:36] e-flexbox,
	 * e-div-block ([M ergebnis-3] e-flexbox).
	 */
	const STRUCTURE = array( 'section', 'column', 'container', 'e-flexbox', 'e-div-block' );

	/** Structure elements on the measured pages: container [M ergebnis-1], e-flexbox [M ergebnis-3]. */
	const STRUCTURE_MEASURED = array( 'container', 'e-flexbox' );

	/** Widget types whose names start like this are WordPress widgets [SVN elementor@4.3.3 includes/widgets/wordpress.php:54]. */
	const WP_WIDGET_PREFIX = 'wp-widget-';

	/**
	 * B1 of the plan review: a name containing one of these is never a
	 * field, whatever its control type ("custom_attributes", "button_css_id",
	 * "css_classes", "html_tag", "embed_code", "selector" …).
	 */
	const DENIED_STEMS = array( 'attr', 'class', 'css', 'js', 'code', 'html', 'embed', 'selector' );

	/**
	 * Whole words of a name that are never a field: tags, scripts (as a word,
	 * so "description" stays readable), and secrets an integration may keep
	 * in a text control (API keys, tokens, passwords, webhook addresses).
	 */
	const DENIED_NAME_WORDS = array( 'tag', 'tags', 'key', 'keys', 'apikey', 'api', 'token', 'tokens', 'secret', 'password', 'passwd', 'pwd', 'nonce', 'webhook', 'webhooks' );

	/** Note on widgets locked because Elementor is not loaded and the table does not know them. */
	const NOTE_NOT_LOADED = 'Elementor is not loaded here, so only the widgets of AlphaBridge\'s own table are read';

	/**
	 * The widget table: fields where Elementor is not loaded (and the kind of
	 * a field where it is), locks always. Format:
	 *   type => [
	 *     'measured' => bool,  read from a page Elementor saved [M]; else read
	 *                          from Elementor's code, and the element says so;
	 *     'fields'   => [ control name => kind,
	 *                     repeater name => [ field name => kind ] ],
	 *     'locked'   => reason, 'global' => bool,
	 *     'ref'      => path to the id of the post holding the content
	 *                   (element keys, dots for nesting) ].
	 * Kinds: text, heading, html (a string setting), url (setting['url'] in
	 * V3), image (setting['id'], else setting['url']). V4 entries take the
	 * value from its $$type; the table gives only the kind.
	 */
	const WIDGETS = array(
		// [M ergebnis-1] heading → title; [SVN elementor@4.3.3 includes/widgets/heading.php:181 title TEXTAREA, :197 link URL].
		'heading'       => array(
			'measured' => true,
			'fields'   => array(
				'title' => 'heading',
				'link'  => 'url',
			),
		),
		// [M ergebnis-1, -4] text-editor → editor; [SVN elementor@4.3.3 includes/widgets/text-editor.php:137 editor WYSIWYG].
		'text-editor'   => array(
			'measured' => true,
			'fields'   => array( 'editor' => 'html' ),
		),
		// [M ergebnis-1] button → text, link.url; [SVN elementor@4.3.3 includes/widgets/traits/button-trait.php:82 text TEXT, :96 link URL].
		'button'        => array(
			'measured' => true,
			'fields'   => array(
				'text' => 'text',
				'link' => 'url',
			),
		),
		// [SVN elementor@4.3.3 includes/widgets/image.php:130 image MEDIA, :172 caption TEXT, :206 link URL].
		'image'         => array(
			'measured' => false,
			'fields'   => array(
				'image'   => 'image',
				'caption' => 'text',
				'link'    => 'url',
			),
		),
		// [SVN elementor@4.3.3 includes/widgets/icon-box.php:164 title_text TEXT, :178 description_text TEXTAREA, :192 link URL].
		'icon-box'      => array(
			'measured' => false,
			'fields'   => array(
				'title_text'       => 'heading',
				'description_text' => 'text',
				'link'             => 'url',
			),
		),
		// [SVN elementor@4.3.3 includes/widgets/icon-list.php:177 icon_list REPEATER with :139 text TEXT, :166 link URL].
		'icon-list'     => array(
			'measured' => false,
			'fields'   => array(
				'icon_list' => array(
					'text' => 'text',
					'link' => 'url',
				),
			),
		),
		// [SVN elementor@4.3.3 includes/widgets/image-box.php:114 image MEDIA, :139 title_text TEXT, :153 description_text TEXTAREA, :167 link URL].
		'image-box'     => array(
			'measured' => false,
			'fields'   => array(
				'image'            => 'image',
				'title_text'       => 'heading',
				'description_text' => 'text',
				'link'             => 'url',
			),
		),
		// [SVN elementor@4.3.3 includes/widgets/testimonial.php:136 testimonial_content TEXTAREA, :149 testimonial_image MEDIA, :171 testimonial_name TEXT, :186 testimonial_job TEXT, :201 link URL].
		'testimonial'   => array(
			'measured' => false,
			'fields'   => array(
				'testimonial_content' => 'text',
				'testimonial_image'   => 'image',
				'testimonial_name'    => 'text',
				'testimonial_job'     => 'text',
				'link'                => 'url',
			),
		),
		// V4. [M ergebnis-3] e-heading title as escaped-html; [M ergebnis-2] title: escaped-html|dynamic|overridable, link: link;
		// [SVN elementor@4.3.3 modules/atomic-widgets/elements/atomic-heading/atomic-heading.php:69 title, :74 link].
		'e-heading'     => array(
			'measured' => true,
			'fields'   => array(
				'title' => 'heading',
				'link'  => 'url',
			),
		),
		// [M ergebnis-3] e-paragraph paragraph; [SVN … atomic-paragraph/atomic-paragraph.php:65 paragraph (escaped-html, lists and links allowed), :75 link].
		'e-paragraph'   => array(
			'measured' => true,
			'fields'   => array(
				'paragraph' => 'html',
				'link'      => 'url',
			),
		),
		// [M ergebnis-3] e-button text; [SVN … atomic-button/atomic-button.php:57 text, :62 link].
		'e-button'      => array(
			'measured' => true,
			'fields'   => array(
				'text' => 'text',
				'link' => 'url',
			),
		),
		// [M ergebnis-1 elementor_atomic_schema, ergebnis-2] e-image image, link; no page with an image was saved.
		'e-image'       => array(
			'measured' => false,
			'fields'   => array(
				'image' => 'image',
				'link'  => 'url',
			),
		),

		// Locks. [Plan §8] html, shortcode; [SVN elementor@4.3.3 includes/widgets/html.php, shortcode.php].
		'html'          => array( 'locked' => 'html widget' ),
		'shortcode'     => array( 'locked' => 'shortcode' ),
		// Elementor Pro's global widget: its content is a library post, "templateID" on the element [Elementor #10725, WPML].
		'global'        => array(
			'locked' => 'global element',
			'global' => true,
			'ref'    => 'templateID',
		),
		// Elementor Pro's template widget: shows a library post [WPML].
		'template'      => array(
			'locked' => 'global element',
			'global' => true,
			'ref'    => 'settings.template_id',
		),
		// V4 component instance [SVN elementor@4.3.3 modules/components/prop-types/component-instance-prop-type.php:14, :32-37].
		'e-component'   => array(
			'locked' => 'global element',
			'global' => true,
			'ref'    => 'settings.component_instance.value.component_id.value',
		),
		// Forms keep their configuration (recipients, webhooks, integration keys) in text controls
		// [developers.elementor.com/docs/form-actions/advanced-example]; V4 form [SVN … atomic-form/atomic-form.php:69, :118-142].
		'form'          => array( 'locked' => 'form' ),
		'e-form'        => array( 'locked' => 'form' ),
	);

	/**
	 * Normalised widget table for the outline being read.
	 *
	 * @var array<string,array>
	 */
	private $table = array();

	/**
	 * Adapter id, the builder id of signatures.php.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'elementor';
	}

	/**
	 * Measured: the V3 and V4 pages of the measurement folder read as Elementor
	 * saved them. Elements read without a measured entry say so in their note.
	 *
	 * @return bool
	 */
	public function verified(): bool {
		return true;
	}

	/**
	 * The page tree as Elementor reads it (Document::get_json_meta():
	 * a JSON string is decoded, an empty value is an empty page), or null
	 * when a stored value cannot be read as a tree.
	 * [SVN elementor@4.3.3 core/base/document.php:1041-1053]
	 *
	 * @param WP_Post $post Post.
	 * @return array|null
	 */
	public static function elements_data( $post ): ?array {
		if ( ! is_object( $post ) ) {
			return null;
		}
		$meta = get_post_meta( (int) $post->ID, self::DATA_KEY, true );
		if ( is_string( $meta ) && '' !== $meta ) {
			$meta = json_decode( $meta, true );
			if ( ! is_array( $meta ) ) {
				return null;
			}
		}
		if ( empty( $meta ) ) {
			return array();
		}
		return is_array( $meta ) ? $meta : null;
	}

	/**
	 * The page as an outline.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $o    include_locked (true), max_elements (500), max_field_chars.
	 * @return array<int,array>
	 */
	public function outline( $post, array $o ): array {
		$o    = array(
			'include_locked'  => array_key_exists( 'include_locked', $o ) ? (bool) $o['include_locked'] : true,
			'max_elements'    => isset( $o['max_elements'] ) ? max( 1, (int) $o['max_elements'] ) : 500,
			'max_field_chars' => AB_MCP_Builders::field_max_chars( isset( $o['max_field_chars'] ) ? (int) $o['max_field_chars'] : null ),
		);
		$data = self::elements_data( $post );
		if ( null === $data ) {
			if ( ! $o['include_locked'] ) {
				return array();
			}
			return array(
				array(
					'id'     => 'e0',
					'type'   => self::DATA_KEY,
					'parent' => null,
					'depth'  => 0,
					'locked' => true,
					'reason' => 'unreadable data',
					'note'   => self::DATA_KEY . ' is not a JSON list of elements, so Elementor shows nothing of it either',
				),
			);
		}
		$this->table = self::widgets();
		$counts      = array();
		self::count_ids( $data, $counts );
		$out = array();
		$this->walk( $data, array(), null, 0, $o, $counts, $out );
		return $out;
	}

	/**
	 * Whether a setting name can never be a field (B1 of the plan review):
	 * it contains attr, class, css, js, code, html, embed or selector; it
	 * ends in "id" (a media control's own "id" is read as the image, never
	 * a control called …id); a word of it is a tag, a script or a secret
	 * (DENIED_NAME_WORDS); or the block reader refuses it (style, svg,
	 * iframe, event handlers such as "onclick").
	 *
	 * @param string $name Control or setting name.
	 * @return bool
	 */
	public static function denied_name( $name ): bool {
		$name  = (string) $name;
		$lower = strtolower( $name );
		if ( '' === $lower ) {
			return true;
		}
		foreach ( self::DENIED_STEMS as $stem ) {
			if ( false !== strpos( $lower, $stem ) ) {
				return true;
			}
		}
		if ( 'id' === substr( $lower, -2 ) ) {
			return true;
		}
		$words = preg_split( '~(?<=[a-z0-9])(?=[A-Z])|[^A-Za-z0-9]+~', $name );
		foreach ( is_array( $words ) ? $words : array() as $word ) {
			$word = strtolower( $word );
			if ( '' === $word ) {
				continue;
			}
			if ( in_array( $word, self::DENIED_NAME_WORDS, true ) || 0 === strpos( $word, 'script' ) || 'script' === substr( $word, -6 ) ) {
				return true;
			}
		}
		return AB_MCP_Block_Reader::denied_segment( $name );
	}

	/**
	 * The widget table: WIDGETS plus the filter ab_mcp_builder_elementor_widgets,
	 * normalised. A field whose name is denied or whose kind is unknown is
	 * dropped, so a filter can add readable widgets and locks but cannot open
	 * a code field.
	 *
	 * @return array<string,array>
	 */
	public static function widgets(): array {
		/**
		 * Elementor widgets AlphaBridge reads without Elementor loaded, and
		 * widgets it never reads (locks). Format: see
		 * AB_MCP_Builder_Adapter_Elementor::WIDGETS.
		 *
		 * @param array $widgets Widget type => entry.
		 */
		$raw = apply_filters( 'ab_mcp_builder_elementor_widgets', self::WIDGETS );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $type => $entry ) {
			if ( ! is_string( $type ) || '' === $type || ! is_array( $entry ) ) {
				continue;
			}
			$norm = array(
				'measured' => ! empty( $entry['measured'] ),
				'fields'   => array(),
				'locked'   => isset( $entry['locked'] ) && is_string( $entry['locked'] ) ? $entry['locked'] : '',
				'global'   => ! empty( $entry['global'] ),
				'ref'      => isset( $entry['ref'] ) && is_string( $entry['ref'] ) ? $entry['ref'] : '',
			);
			foreach ( isset( $entry['fields'] ) && is_array( $entry['fields'] ) ? $entry['fields'] : array() as $name => $kind ) {
				if ( ! is_string( $name ) || self::denied_name( $name ) ) {
					continue;
				}
				if ( is_array( $kind ) ) {
					$sub = array();
					foreach ( $kind as $sub_name => $sub_kind ) {
						if ( is_string( $sub_name ) && ! self::denied_name( $sub_name ) && in_array( $sub_kind, AB_MCP_Builders::FIELD_KINDS, true ) ) {
							$sub[ $sub_name ] = $sub_kind;
						}
					}
					if ( array() !== $sub ) {
						$norm['fields'][ $name ] = $sub;
					}
				} elseif ( in_array( $kind, AB_MCP_Builders::FIELD_KINDS, true ) ) {
					$norm['fields'][ $name ] = $kind;
				}
			}
			$out[ $type ] = $norm;
		}
		return $out;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Walk one level of elements, adding them in page order.
	 *
	 * @param array       $elements Elements.
	 * @param int[]       $path     Path of the parent.
	 * @param string|null $parent   Parent element id.
	 * @param int         $depth    Depth.
	 * @param array       $o        Options.
	 * @param array       $counts   Occurrences of each Elementor id.
	 * @param array       $out      Elements (by reference).
	 * @return bool False once max_elements is reached.
	 */
	private function walk( array $elements, array $path, $parent, $depth, array $o, array $counts, array &$out ) {
		foreach ( $elements as $i => $el ) {
			if ( count( $out ) >= $o['max_elements'] ) {
				return false;
			}
			if ( ! is_array( $el ) ) {
				continue;
			}
			$p        = array_merge( $path, array( (int) $i ) );
			$el_type  = isset( $el['elType'] ) && is_string( $el['elType'] ) ? $el['elType'] : '';
			$widget   = 'widget' === $el_type && isset( $el['widgetType'] ) && is_string( $el['widgetType'] ) ? $el['widgetType'] : '';
			$type     = 'widget' === $el_type ? $widget : $el_type;
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			$id       = self::element_id( $el, $p, $counts );
			$element  = array(
				'id'     => $id,
				'type'   => '' !== $type ? $type : 'unknown',
				'parent' => $parent,
				'depth'  => $depth,
			);

			$read = $this->classify( $el, $el_type, $widget, $type, $settings );
			if ( isset( $read['locked'] ) ) {
				if ( $o['include_locked'] ) {
					$element['locked'] = true;
					$element['reason'] = $read['locked'];
					if ( ! empty( $read['global'] ) ) {
						$element['global'] = true;
					}
					if ( isset( $read['note'] ) ) {
						$element['note'] = $read['note'];
					}
					$out[] = $element;
				}
				continue; // Children of a locked element are not listed.
			}

			$notes  = array();
			$fields = array();
			if ( 'typed' === $read['mode'] ) {
				$this->typed_fields( $settings, $read['entry'], $o, $fields, $notes );
			} elseif ( 'controls' === $read['mode'] ) {
				$this->control_fields( $settings, $read['controls'], $read['entry'], $o, $fields, $notes );
			} else {
				$this->table_fields( $settings, $read['entry'], $o, $fields, $notes );
			}
			if ( array() !== $fields ) {
				$element['fields'] = $fields;
				if ( ! $read['measured'] ) {
					$notes[] = AB_MCP_Block_Reader::NOTE_UNMEASURED;
				}
			}
			if ( array() !== $notes ) {
				$element['note'] = implode( '; ', array_values( array_unique( $notes ) ) );
			}
			$out[] = $element;

			$children = isset( $el['elements'] ) && is_array( $el['elements'] ) ? $el['elements'] : array();
			if ( $depth < 512 && array() !== $children && ! $this->walk( $children, $p, $id, $depth + 1, $o, $counts, $out ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * How one element is read: locked (with reason), or a mode — 'typed'
	 * (V4 settings carry their $$type), 'controls' (Elementor loaded: the
	 * registered controls) or 'table' (the widget table).
	 *
	 * @param array  $el       Element.
	 * @param string $el_type  elType.
	 * @param string $widget   widgetType ('' for other elements).
	 * @param string $type     Widget type or element type.
	 * @param array  $settings Settings.
	 * @return array
	 */
	private function classify( array $el, $el_type, $widget, $type, array $settings ) {
		$entry = isset( $this->table[ $type ] ) ? $this->table[ $type ] : null;

		if ( '' === $type ) {
			return array( 'locked' => 'unknown element type' );
		}
		if ( null !== $entry && '' !== $entry['locked'] ) {
			$out = array( 'locked' => $entry['locked'] );
			if ( $entry['global'] ) {
				$out['global'] = true;
			}
			$ref = '' !== $entry['ref'] ? self::path_value( $el, $entry['ref'] ) : null;
			if ( is_numeric( $ref ) && (int) $ref > 0 ) {
				$out['note'] = sprintf( 'its content is post #%d', (int) $ref );
			}
			return $out;
		}
		if ( 'widget' === $el_type && 0 === strpos( $widget, self::WP_WIDGET_PREFIX ) ) {
			return array(
				'locked' => 'WordPress widget',
				'note'   => 'a classic WordPress widget placed in Elementor; change it in the Elementor editor',
			);
		}

		$atomic   = 0 === strpos( $type, 'e-' ) || self::has_typed_values( $settings );
		$measured = null !== $entry ? $entry['measured'] : in_array( $type, self::STRUCTURE_MEASURED, true );
		$manager  = self::elements_manager();
		$known    = null;
		$unknown  = self::NOTE_NOT_LOADED;
		if ( null !== $manager ) {
			try {
				$known = $manager->get_element( $el_type, 'widget' === $el_type ? $widget : null );
			} catch ( Throwable $e ) {
				$manager = null; // Elementor could not answer: read as if it were not loaded.
			}
		}

		if ( null !== $manager ) {
			if ( ! is_object( $known ) ) {
				// Elementor neither shows such an element nor keeps it when the
				// page is saved [SVN elementor@4.3.3 core/base/document.php:1929-1941, :1074-1115].
				return array(
					'locked' => 'widget' === $el_type ? 'unregistered widget' : 'unknown element type',
					'note'   => 'not registered on this site, for example because its plugin is inactive: Elementor does not show it, and saving the page in Elementor removes it',
				);
			}
			if ( $atomic ) {
				return array(
					'mode'     => 'typed',
					'entry'    => $entry,
					'measured' => $measured,
				);
			}
			$controls = null;
			try {
				$controls = method_exists( $known, 'get_controls' ) ? $known->get_controls() : null;
			} catch ( Throwable $e ) {
				$controls = null;
			}
			if ( is_array( $controls ) ) {
				return array(
					'mode'     => 'controls',
					'controls' => $controls,
					'entry'    => $entry,
					'measured' => $measured,
				);
			}
			// Registered, but its controls could not be read: the table, if it knows the type.
			$unknown = 'Elementor could not list the controls of this element, so it is read only if AlphaBridge\'s own table knows it';
		}

		if ( $atomic ) {
			return array(
				'mode'     => 'typed',
				'entry'    => $entry,
				'measured' => $measured,
			);
		}
		if ( null !== $entry || ( 'widget' !== $el_type && in_array( $el_type, self::STRUCTURE, true ) ) ) {
			return array(
				'mode'     => 'table',
				'entry'    => $entry,
				'measured' => $measured,
			);
		}
		return array(
			'locked' => 'unknown element type',
			'note'   => $unknown,
		);
	}

	/**
	 * Fields of a V3 element from the widget table (Elementor not loaded):
	 * only what the page stores, defaults are unknown here.
	 *
	 * @param array      $settings Settings.
	 * @param array|null $entry    Table entry.
	 * @param array      $o        Options.
	 * @param array      $fields   Fields (by reference).
	 * @param array      $notes    Notes (by reference).
	 */
	private function table_fields( array $settings, $entry, array $o, array &$fields, array &$notes ) {
		if ( null === $entry ) {
			return;
		}
		foreach ( $entry['fields'] as $name => $kind ) {
			if ( is_array( $kind ) ) {
				$items = isset( $settings[ $name ] ) && is_array( $settings[ $name ] ) ? $settings[ $name ] : array();
				foreach ( array_values( $items ) as $k => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					foreach ( $kind as $sub => $sub_kind ) {
						$this->add_v3_field( $name . '.' . $k . '.' . $sub, $sub, $sub_kind, $item, false, null, $o, $fields, $notes );
					}
				}
				continue;
			}
			$this->add_v3_field( $name, $name, $kind, $settings, false, null, $o, $fields, $notes );
		}
	}

	/**
	 * Fields of a V3 element from its registered controls (Elementor loaded).
	 *
	 * @param array      $settings Settings.
	 * @param array      $controls Controls of the element type, by name.
	 * @param array|null $entry    Table entry (gives kinds where it names the field).
	 * @param array      $o        Options.
	 * @param array      $fields   Fields (by reference).
	 * @param array      $notes    Notes (by reference).
	 */
	private function control_fields( array $settings, array $controls, $entry, array $o, array &$fields, array &$notes ) {
		$named = null !== $entry ? $entry['fields'] : array();
		foreach ( $controls as $control ) {
			if ( ! is_array( $control ) || ! isset( $control['name'], $control['type'], $control['tab'] ) || ! is_string( $control['name'] )
				|| self::CONTENT_TAB !== $control['tab'] || self::denied_name( $control['name'] ) ) {
				continue;
			}
			$name = $control['name'];
			if ( self::REPEATER === $control['type'] ) {
				$sub_controls = isset( $control['fields'] ) && is_array( $control['fields'] ) ? $control['fields'] : array();
				$items        = isset( $settings[ $name ] ) ? $settings[ $name ] : ( isset( $control['default'] ) ? $control['default'] : array() );
				$defaulted    = ! isset( $settings[ $name ] );
				foreach ( is_array( $items ) ? array_values( $items ) : array() as $k => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					foreach ( $sub_controls as $sub ) {
						if ( ! is_array( $sub ) || ! isset( $sub['name'], $sub['type'] ) || ! is_string( $sub['name'] ) || ! self::content_type( $sub['type'] ) ) {
							continue;
						}
						$kind = self::kind_for( $sub['type'], isset( $named[ $name ] ) && is_array( $named[ $name ] ) && isset( $named[ $name ][ $sub['name'] ] ) ? $named[ $name ][ $sub['name'] ] : null );
						$this->add_v3_field( $name . '.' . $k . '.' . $sub['name'], $sub['name'], $kind, $item, $defaulted, $sub, $o, $fields, $notes );
					}
				}
				continue;
			}
			if ( ! self::content_type( $control['type'] ) ) {
				continue;
			}
			$kind = self::kind_for( $control['type'], isset( $named[ $name ] ) && is_string( $named[ $name ] ) ? $named[ $name ] : null );
			$this->add_v3_field( $name, $name, $kind, $settings, false, $control, $o, $fields, $notes );
		}
	}

	/**
	 * One V3 field: the stored value, else (with a control) the control's
	 * default — what Elementor renders [SVN elementor@4.3.3
	 * includes/controls/base-data.php:56-68]. A value filled by a dynamic tag
	 * is left out and noted.
	 *
	 * @param string     $field_name Name in the outline.
	 * @param string     $key        Settings key in $settings.
	 * @param string     $kind       Kind.
	 * @param array      $settings   Settings (or one repeater item).
	 * @param bool       $defaulted  Whether $settings itself is a default (repeater not stored).
	 * @param array|null $control    Control, or null for the table.
	 * @param array      $o          Options.
	 * @param array      $fields     Fields (by reference).
	 * @param array      $notes      Notes (by reference).
	 */
	private function add_v3_field( $field_name, $key, $kind, array $settings, $defaulted, $control, array $o, array &$fields, array &$notes ) {
		if ( self::denied_name( $key ) ) {
			return;
		}
		if ( isset( $settings[ self::DYNAMIC_KEY ] ) && is_array( $settings[ self::DYNAMIC_KEY ] ) && ! empty( $settings[ self::DYNAMIC_KEY ][ $key ] ) ) {
			$notes[] = $field_name . ': dynamic value, not read';
			return;
		}
		$stored = isset( $settings[ $key ] );
		if ( $stored ) {
			$raw = $settings[ $key ];
		} elseif ( null !== $control && isset( $control['default'] ) ) {
			$raw = $control['default'];
		} else {
			return;
		}
		$value = self::v3_value( $kind, $raw );
		if ( null === $value ) {
			return;
		}
		if ( false === $value ) {
			$notes[] = $field_name . ': an address that would run code, left out';
			return;
		}
		if ( ! $stored || $defaulted ) {
			$notes[] = $field_name . ': Elementor\'s default, not stored on the page';
		}
		$fields[ $field_name ] = AB_MCP_Builders::cap_field(
			array(
				'kind'  => $kind,
				'value' => $value,
			),
			$o['max_field_chars']
		);
	}

	/**
	 * A V3 value shaped by its kind: null when empty or of the wrong shape,
	 * false when it is an address that could run code.
	 *
	 * @param string $kind Kind.
	 * @param mixed  $raw  Stored value.
	 * @return string|int|false|null
	 */
	private static function v3_value( $kind, $raw ) {
		switch ( $kind ) {
			case 'url':
				$url = is_array( $raw ) && isset( $raw['url'] ) && is_string( $raw['url'] ) ? trim( $raw['url'] ) : '';
				if ( '' === $url ) {
					return null;
				}
				$safe = AB_MCP_Builder_Html::safe_url( $url );
				return null === $safe ? false : $safe;
			case 'image':
				if ( ! is_array( $raw ) ) {
					return null;
				}
				if ( isset( $raw['id'] ) && is_numeric( $raw['id'] ) && (int) $raw['id'] > 0 ) {
					return (int) $raw['id'];
				}
				$url = isset( $raw['url'] ) && is_string( $raw['url'] ) ? trim( $raw['url'] ) : '';
				if ( '' === $url ) {
					return null;
				}
				$safe = AB_MCP_Builder_Html::safe_url( $url, true );
				return null === $safe ? false : $safe;
			case 'html':
				$text = is_string( $raw ) || is_int( $raw ) || is_float( $raw ) ? AB_MCP_Builder_Html::simple( (string) $raw ) : '';
				return '' === $text ? null : $text;
			default: // text, heading.
				$text = is_string( $raw ) || is_int( $raw ) || is_float( $raw ) ? AB_MCP_Builders::field_text( (string) $raw ) : '';
				return '' === $text ? null : $text;
		}
	}

	/**
	 * Fields of a V4 (atomic) element: every setting whose $$type is text,
	 * a link or an image, under an allowed name. The table gives the kind
	 * where it names the field; otherwise text is html (Elementor allows
	 * inline tags in it). A component's overridable value is read as its
	 * own value (origin_value).
	 * [SVN elementor@4.3.3 modules/atomic-widgets/prop-types/
	 * escaped-html-prop-type.php, html-prop-type.php, link-prop-type.php,
	 * image-prop-type.php, image-src-prop-type.php; modules/components/
	 * prop-types/overridable-prop-type.php; modules/atomic-widgets/
	 * dynamic-tags/dynamic-prop-type.php]
	 *
	 * @param array      $settings Settings.
	 * @param array|null $entry    Table entry.
	 * @param array      $o        Options.
	 * @param array      $fields   Fields (by reference).
	 * @param array      $notes    Notes (by reference).
	 */
	private function typed_fields( array $settings, $entry, array $o, array &$fields, array &$notes ) {
		$named = null !== $entry ? $entry['fields'] : array();
		foreach ( $settings as $name => $prop ) {
			if ( ! is_string( $name ) || self::denied_name( $name ) || ! is_array( $prop ) || ! isset( $prop['$$type'] ) ) {
				continue;
			}
			if ( 'overridable' === $prop['$$type'] && isset( $prop['value']['origin_value'] ) && is_array( $prop['value']['origin_value'] ) ) {
				$prop = $prop['value']['origin_value'];
			}
			$value_type = isset( $prop['$$type'] ) && is_string( $prop['$$type'] ) ? $prop['$$type'] : '';
			$inner      = isset( $prop['value'] ) ? $prop['value'] : null;
			$kind       = null;
			$value      = null;
			switch ( $value_type ) {
				case 'dynamic':
					$notes[] = $name . ': dynamic value, not read';
					continue 2;
				case 'html-v2':
				case 'html-v3':
					// Rich text as a tree of child nodes [SVN elementor@4.3.3
					// modules/atomic-widgets/prop-types/html-v2-prop-type.php,
					// html-v3-prop-type.php]: not measured, so not read, but named.
					$notes[] = $name . ': rich text in a format not read yet (' . $value_type . ')';
					continue 2;
				case 'escaped-html':
				case 'html':
					$kind  = isset( $named[ $name ] ) && in_array( $named[ $name ], array( 'text', 'heading', 'html' ), true ) ? $named[ $name ] : 'html';
					$value = self::v3_value( $kind, $inner );
					break;
				case 'link':
					$kind = 'url';
					$dest = is_array( $inner ) && isset( $inner['destination'] ) && is_array( $inner['destination'] ) ? $inner['destination'] : null;
					if ( null === $dest ) {
						continue 2;
					}
					if ( isset( $dest['$$type'] ) && 'url' === $dest['$$type'] && isset( $dest['value'] ) && is_string( $dest['value'] ) ) {
						$value = self::v3_value( 'url', array( 'url' => $dest['value'] ) );
					} else {
						$post_id = self::path_value( $dest, 'value.id.value' );
						if ( is_numeric( $post_id ) && (int) $post_id > 0 ) {
							$notes[] = sprintf( '%s: links to post #%d', $name, (int) $post_id );
						}
						continue 2;
					}
					break;
				case 'image':
					$kind = 'image';
					$src  = self::path_value( $prop, 'value.src.value' );
					if ( ! is_array( $src ) ) {
						continue 2;
					}
					$image_id = self::path_value( $src, 'id.value' );
					$url      = self::path_value( $src, 'url.value' );
					$value    = self::v3_value(
						'image',
						array(
							'id'  => is_numeric( $image_id ) ? $image_id : 0,
							'url' => is_string( $url ) ? $url : '',
						)
					);
					break;
				default:
					continue 2;
			}
			if ( null === $value ) {
				continue;
			}
			if ( false === $value ) {
				$notes[] = $name . ': an address that would run code, left out';
				continue;
			}
			$fields[ $name ] = AB_MCP_Builders::cap_field(
				array(
					'kind'  => $kind,
					'value' => $value,
				),
				$o['max_field_chars']
			);
		}
	}

	/**
	 * Whether a control type holds visible content (CONTROL_KINDS).
	 *
	 * @param mixed $type Control type.
	 * @return bool
	 */
	private static function content_type( $type ) {
		return is_string( $type ) && array_key_exists( $type, self::CONTROL_KINDS );
	}

	/**
	 * The kind of a field: the table's, where it names the field and the
	 * value has the same shape (a string for text, heading and html), else
	 * the control type's.
	 *
	 * @param string      $control_type Control type.
	 * @param string|null $named        Kind from the table, or null.
	 * @return string
	 */
	private static function kind_for( $control_type, $named ) {
		$kind    = self::CONTROL_KINDS[ $control_type ];
		$strings = array( 'text', 'heading', 'html' );
		if ( is_string( $named ) && ( $named === $kind || ( in_array( $named, $strings, true ) && in_array( $kind, $strings, true ) ) ) ) {
			return $named;
		}
		return $kind;
	}

	/**
	 * Elementor's elements manager, when Elementor is loaded and initialised
	 * (it builds its managers on init [SVN elementor@4.3.3
	 * includes/plugin.php:56, :106, :620-623, :692]); null otherwise.
	 *
	 * @return object|null
	 */
	protected static function elements_manager() {
		if ( ! class_exists( '\Elementor\Plugin', false ) ) {
			return null;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( ! is_object( $plugin ) || ! isset( $plugin->elements_manager ) || ! is_object( $plugin->elements_manager ) || ! method_exists( $plugin->elements_manager, 'get_element' ) ) {
			return null;
		}
		return $plugin->elements_manager;
	}

	/**
	 * Whether any setting carries a $$type (V4 data).
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private static function has_typed_values( array $settings ) {
		foreach ( $settings as $value ) {
			if ( is_array( $value ) && isset( $value['$$type'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A value at a dotted path, or null.
	 *
	 * @param array  $data Data.
	 * @param string $path Keys joined by ".".
	 * @return mixed
	 */
	private static function path_value( array $data, $path ) {
		$value = $data;
		foreach ( explode( '.', (string) $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	/**
	 * The element id: Elementor's, where it is usable and unique on the page,
	 * else the path. An Elementor id that looks like a path ("e12") is not
	 * used, so the two can never be confused.
	 *
	 * @param array $el     Element.
	 * @param int[] $path   Path.
	 * @param array $counts Occurrences per id.
	 * @return string
	 */
	private static function element_id( array $el, array $path, array $counts ) {
		if ( isset( $el['id'] ) && is_scalar( $el['id'] ) && ! is_bool( $el['id'] ) ) {
			$candidate = (string) $el['id'];
			if ( AB_MCP_Block_Reader::usable_id( $candidate ) && 1 !== preg_match( '~^e\d+(?:\.\d+)*$~', $candidate )
				&& 1 === ( isset( $counts[ $candidate ] ) ? $counts[ $candidate ] : 0 ) ) {
				return $candidate;
			}
		}
		return 'e' . implode( '.', $path );
	}

	/**
	 * Count every Elementor id on the page, so a duplicated one falls back to
	 * the path for all its copies.
	 *
	 * @param array $elements Elements.
	 * @param array $counts   Counts (by reference).
	 * @param int   $depth    Nesting depth; far deeper than any page is not followed.
	 */
	private static function count_ids( array $elements, array &$counts, $depth = 0 ) {
		if ( $depth > 512 ) {
			return;
		}
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) && is_scalar( $el['id'] ) && ! is_bool( $el['id'] ) ) {
				$id            = (string) $el['id'];
				$counts[ $id ] = ( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ) + 1;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::count_ids( $el['elements'], $counts, $depth + 1 );
			}
		}
	}
}
