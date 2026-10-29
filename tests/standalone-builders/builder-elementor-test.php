<?php
/**
 * The Elementor writer (AB_MCP_Builder_Writer_Elementor): V3 through the
 * page's Elementor document, V4 through Elementor's own abilities, the guards
 * before save() (every element registered and kept, kses for accounts without
 * unfiltered_html, save() === false), publish=false into Elementor's
 * autosave, fields by the free core's outline (repeaters, image id and URL,
 * the name deny list), add-on widgets read only, and replace() for scope text.
 *
 * Elementor is a stand-in, written from Elementor 4.3.3
 * (plugins.svn.wordpress.org/elementor/tags/4.3.3/, read 02.10.2026); each
 * class names the lines it follows and where it is narrower. No Elementor
 * code is copied. The measured pages (ergebnis-1, ergebnis-3 of the
 * measurement folder docs/recherche/2026-09-30-page-builder-messung/,
 * Elementor 4.3.3, WordPress 7.1.2) are taken byte for byte; every other page
 * is built here from Elementor's control definitions and says so.
 *
 * Run: php test/builder-elementor-test.php        (after ./sync-free-core.sh <free>)
 *      php test/builder-elementor-test.php old    (an Elementor before 4.3: no draft path, no abilities, no editor marker)
 */

namespace {
	$GLOBALS['ab_scenario'] = isset( $argv[1] ) ? $argv[1] : 'main';
	if ( ! in_array( $GLOBALS['ab_scenario'], array( 'main', 'old' ), true ) ) {
		fwrite( STDERR, "Unbekanntes Szenario '{$GLOBALS['ab_scenario']}' — erlaubt: main, old\n" );
		exit( 2 );
	}
	require dirname( __DIR__ ) . '/standalone-lib/builder-harness.php';

	define( 'ELEMENTOR_VERSION', '4.3.3' );
	// Classes defined in this file count as Elementor's own (the writer asks
	// where a widget's class lives); the add-on below is written elsewhere.
	define( 'ELEMENTOR_PATH', __DIR__ . '/' );

	function get_post_status( $id = null ) {
		$id = is_object( $id ) ? $id->ID : $id;
		return isset( $GLOBALS['db_posts'][ (int) $id ] ) ? $GLOBALS['db_posts'][ (int) $id ]['post_status'] : false;
	}
	// WordPress core 7.0.2, wp-includes/functions.php:674-731 — verbatim, so the replace
	// engine meets the same notion of "looks serialized" as in production.
	if ( ! function_exists( 'is_serialized' ) ) {
		function is_serialized( $data, $strict = true ) {
			// If it isn't a string, it isn't serialized.
			if ( ! is_string( $data ) ) {
				return false;
			}
			$data = trim( $data );
			if ( 'N;' === $data ) {
				return true;
			}
			if ( strlen( $data ) < 4 ) {
				return false;
			}
			if ( ':' !== $data[1] ) {
				return false;
			}
			if ( $strict ) {
				$lastc = substr( $data, -1 );
				if ( ';' !== $lastc && '}' !== $lastc ) {
					return false;
				}
			} else {
				$semicolon = strpos( $data, ';' );
				$brace     = strpos( $data, '}' );
				// Either ; or } must exist.
				if ( false === $semicolon && false === $brace ) {
					return false;
				}
				// But neither must be in the first X characters.
				if ( false !== $semicolon && $semicolon < 3 ) {
					return false;
				}
				if ( false !== $brace && $brace < 4 ) {
					return false;
				}
			}
			$token = $data[0];
			switch ( $token ) {
				case 's':
					if ( $strict ) {
						if ( '"' !== substr( $data, -2, 1 ) ) {
							return false;
						}
					} elseif ( ! str_contains( $data, '"' ) ) {
						return false;
					}
					// Or else fall through.
				case 'a':
				case 'O':
				case 'E':
					return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
				case 'b':
				case 'i':
				case 'd':
					$end = $strict ? '$' : '';
					return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$end/", $data );
			}
			return false;
		}
	}
	function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : addslashes( (string) $v ); }
	/** wp-includes/meta.php update_metadata(): unslashes the value, writes every row of the key of exactly this object id. */
	function update_metadata( $type, $id, $key, $value ) {
		$value = is_string( $value ) ? stripslashes( $value ) : $value;
		$rows  = ab_meta_rows( (int) $id, $key );
		if ( array() === $rows ) { ab_meta_add( (int) $id, $key, maybe_serialize( $value ) ); return true; }
		foreach ( array_keys( $rows ) as $mid ) { $GLOBALS['db_meta'][ $mid ][2] = (string) maybe_serialize( $value ); }
		return true;
	}
	function wp_normalize_path( $p ) { return (string) preg_replace( '~/+~', '/', str_replace( '\\', '/', (string) $p ) ); }
	function map_deep( $value, $cb ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) { $value[ $k ] = map_deep( $v, $cb ); }
			return $value;
		}
		return $cb( $value );
	}
	/*
	 * Autosave revisions: post id => user id => revision id. Narrower than
	 * core (wp-includes/revision.php wp_get_post_autosave(): the revision
	 * named "<id>-autosave-v1" by that author).
	 */
	$GLOBALS['ab_autosaves'] = array();
	function wp_get_post_autosave( $post_id, $user_id = 0 ) {
		$list = isset( $GLOBALS['ab_autosaves'][ (int) $post_id ] ) ? $GLOBALS['ab_autosaves'][ (int) $post_id ] : array();
		if ( 0 === (int) $user_id ) {
			$first = reset( $list );
			return false === $first ? false : get_post( $first );
		}
		return isset( $list[ (int) $user_id ] ) ? get_post( $list[ (int) $user_id ] ) : false;
	}
	function wp_delete_post_revision( $id ) {
		$id = (int) $id;
		unset( $GLOBALS['db_posts'][ $id ] );
		foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
			if ( $r[0] === $id ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
		}
		foreach ( $GLOBALS['ab_autosaves'] as $p => $users ) {
			foreach ( $users as $u => $rid ) {
				if ( $rid === $id ) { unset( $GLOBALS['ab_autosaves'][ $p ][ $u ] ); }
			}
		}
		return true;
	}
	/**
	 * Copy the meta Elementor copies between a post and its revisions
	 * (includes/db.php copy_elementor_meta(): every "_elementor…" key and
	 * the two core keys).
	 */
	function ab_copy_elementor_meta( $from, $to ) {
		$keys = array();
		foreach ( $GLOBALS['db_meta'] as $r ) {
			if ( $r[0] === (int) $from && ( 0 === strpos( $r[1], '_elementor' ) || in_array( $r[1], array( '_wp_page_template', '_thumbnail_id' ), true ) ) ) {
				$keys[ $r[1] ] = $r[2];
			}
		}
		foreach ( $keys as $k => $v ) { update_post_meta( (int) $to, $k, $v ); }
	}
	/** wp_create_post_autosave() as Document::get_autosave( …, true ) calls it (document.php:611-650). */
	function ab_create_autosave( $post_id, $user_id ) {
		$id = ++$GLOBALS['db_next'];
		ab_post( $id, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => (int) $post_id, 'post_content' => '<!-- Created With Elementor -->' ) );
		ab_copy_elementor_meta( $post_id, $id );
		$GLOBALS['ab_autosaves'][ (int) $post_id ][ (int) $user_id ] = $id;
		return $id;
	}

	/* Elementor's abilities, through the Abilities API of WordPress 6.9+. */
	class AB_Test_Ability {
		public $name;
		private $cb;
		public function __construct( $name, $cb ) { $this->name = $name; $this->cb = $cb; }
		public function get_name() { return $this->name; }
		public function execute( $input = null ) {
			$GLOBALS['ab_ability_calls'][] = array( $this->name, $input );
			return call_user_func( $this->cb, $input );
		}
	}
	$GLOBALS['ab_abilities']     = array();
	$GLOBALS['ab_ability_calls'] = array();
	if ( 'main' === $GLOBALS['ab_scenario'] ) {
		function wp_get_ability( string $name ) {
			return isset( $GLOBALS['ab_abilities'][ $name ] ) ? $GLOBALS['ab_abilities'][ $name ] : null;
		}
	}
	$GLOBALS['ab_events'] = array();
}

/* ======================================== Elementor 4.3.3, as stand-ins */

namespace Elementor {
	/** includes/plugin.php:56, :106 — the singleton and the managers the writer reads. */
	class Plugin {
		public static $instance = null;
		public $elements_manager;
		public $documents;
		public $db;
	}
	/**
	 * includes/db.php:225-242 save_plain_text(): the text copy in
	 * post_content, written with wp_update_post() — here simply the strings
	 * of the stored settings, not Elementor's render.
	 */
	class Test_DB {
		public $fail = false;
		public function save_plain_text( $post_id ) {
			if ( $this->fail ) {
				throw new \RuntimeException( 'render failed' );
			}
			$tree  = json_decode( (string) \get_post_meta( (int) $post_id, '_elementor_data', true ), true );
			$texts = array();
			array_walk_recursive( $tree, static function ( $v, $k ) use ( &$texts ) { if ( is_string( $v ) && ! in_array( $k, array( 'id', 'elType', 'widgetType', '$$type', 'version', '_id' ), true ) ) { $texts[] = $v; } } );
			$GLOBALS['db_posts'][ (int) $post_id ]['post_content'] = implode( "\n", $texts );
			$GLOBALS['ab_events'][] = 'plain:' . (int) $post_id;
		}
	}
	/** A registered element type: get_controls() keyed by name (includes/managers/controls.php:817-848). */
	class Test_Element_Type {
		public $name;
		private $controls;
		public function __construct( $name, array $controls ) { $this->name = $name; $this->controls = $controls; }
		public function get_controls( $control_id = null ) { return $this->controls; }
	}
	/** includes/managers/elements.php:101-110 get_element(). */
	class Test_Elements_Manager {
		public $types = array();
		public function get_element( string $el_type, ?string $widget_type = null ) {
			$key = 'widget' === $el_type ? (string) $widget_type : $el_type;
			return isset( $this->types[ $key ] ) ? $this->types[ $key ] : null;
		}
	}
	/** core/documents-manager.php:177-207 get(), :255-262 get_doc_or_auto_save(). */
	class Test_Documents_Manager {
		public function get( $post_id, $from_cache = true ) {
			return get_post( (int) $post_id ) ? new Core\Base\Document( (int) $post_id ) : false;
		}
		public function get_doc_or_auto_save( $id, $user_id = 0 ) {
			$doc = $this->get( $id );
			$auto = $doc ? \wp_get_post_autosave( (int) $id, (int) $user_id ) : false;
			return $auto ? $this->get( $auto->ID ) : $doc;
		}
	}
}

namespace Elementor\Core\Base {
	/**
	 * core/base/document.php, reduced to what a save does to the tables:
	 * save() (:812-907) returns false when the account may not edit the page
	 * (:838), runs kses over the data without unfiltered_html (:847-849),
	 * keeps only elements Elementor can instantiate (get_elements_raw_data(),
	 * :1074-1114: an unregistered element is dropped, children included),
	 * stores the tree as wp_json_encode() (:1380-1392), rewrites the text
	 * copy in post_content (includes/db.php save_plain_text() — here simply
	 * the strings of the settings, not Elementor's render), stamps the
	 * version (:1443-1460), deletes the CSS and element cache (:885-891) and
	 * fires elementor/document/after_save (:893-903).
	 */
	class Document {
		public static $editable  = true;
		public static $drop_also = array(); // types save() drops although registered (an instance that throws).
		private $id;
		public function __construct( $id ) { $this->id = (int) $id; }
		public function get_post() { return get_post( $this->id ); }
		public function get_main_id() {
			$p = get_post( $this->id );
			return ( $p && 'revision' === $p->post_type ) ? (int) $p->post_parent : $this->id;
		}
		/** :611-650 */
		public function get_autosave( $user_id = 0, $create = false ) {
			$user_id = $user_id ? $user_id : get_current_user_id();
			$auto    = \wp_get_post_autosave( $this->id, $user_id );
			if ( $auto ) {
				return new self( $auto->ID );
			}
			return $create ? new self( \ab_create_autosave( $this->id, $user_id ) ) : false;
		}
		public function save( $data ) {
			if ( ! self::$editable ) {
				return false;
			}
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$data = \map_deep( $data, static function ( $v ) { return is_string( $v ) ? \wp_kses_post( $v ) : $v; } );
			}
			if ( isset( $data['elements'] ) && is_array( $data['elements'] ) ) {
				$kept  = self::raw_data( $data['elements'] );
				$texts = array();
				array_walk_recursive( $kept, static function ( $v, $k ) use ( &$texts ) { if ( is_string( $v ) && ! in_array( $k, array( 'id', 'elType', 'widgetType', '$$type', 'version', '_id' ), true ) ) { $texts[] = $v; } } );
				update_post_meta( $this->id, '_elementor_data', json_encode( $kept ) );
				$GLOBALS['db_posts'][ $this->id ]['post_content'] = implode( "\n", $texts );
			}
			update_post_meta( $this->id, '_elementor_version', ELEMENTOR_VERSION );
			delete_post_meta( $this->id, '_elementor_css' );
			delete_post_meta( $this->id, '_elementor_element_cache' );
			$GLOBALS['ab_events'][] = 'save:' . $this->id;
			do_action( 'elementor/document/after_save', $this, $data );
			return true;
		}
		private static function raw_data( array $elements ) {
			$manager = \Elementor\Plugin::$instance->elements_manager;
			$out     = array();
			foreach ( $elements as $el ) {
				$type = 'widget' === $el['elType'] ? ( isset( $el['widgetType'] ) ? $el['widgetType'] : '' ) : $el['elType'];
				if ( null === $manager->get_element( $el['elType'], 'widget' === $el['elType'] ? $type : null ) || in_array( $type, self::$drop_also, true ) ) {
					continue;
				}
				if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$el['elements'] = self::raw_data( $el['elements'] );
				}
				$out[] = $el;
			}
			return $out;
		}
	}
}

namespace Elementor\Core\Utils\Document {
	if ( 'old' !== $GLOBALS['ab_scenario'] ) {
		/** core/utils/document/document-mutator.php:199-279, save_as_draft() with $preserve_live_status. */
		class Document_Mutator {
			public static function instance(): self { return new self(); }
			public function save_as_draft( $document, array $elements, bool $preserve_live_status = false ) {
				if ( ! $preserve_live_status ) {
					throw new \LogicException( 'the writer must never downgrade a live page to a draft' );
				}
				$main = \Elementor\Plugin::$instance->documents->get( $document->get_main_id() );
				if ( ! $main ) {
					return new \WP_Error( 'elementor_not_found', 'Post not found.' );
				}
				$target = in_array( get_post_status( $main->get_main_id() ), array( 'publish', 'private' ), true ) ? $main->get_autosave( 0, true ) : $main;
				if ( ! $target ) {
					return new \WP_Error( 'elementor_autosave_failed', 'Could not create autosave revision.' );
				}
				$saved = $target->save( array( 'elements' => $elements ) );
				if ( ! $saved ) {
					return new \WP_Error( 'elementor_save_failed', 'Could not save document.' );
				}
				return $target;
			}
		}
	}
}

namespace Elementor\Modules\Mcp\Utils {
	if ( 'old' !== $GLOBALS['ab_scenario'] ) {
		/** modules/mcp/utils/editor-sync-state.php:9-129, the transients as an array. */
		class Editor_Sync_State {
			public static $mutation = array();
			public static $unsaved  = array();
			public static function set_mcp_mutation( int $post_id ): void {
				self::$mutation[ $post_id ] = time();
				$GLOBALS['ab_events'][]     = 'mark:' . $post_id;
			}
			public static function has_editor_unsaved( int $post_id ): bool { return ! empty( self::$unsaved[ $post_id ] ); }
			/** :71-75, on elementor/document/after_save: a save clears the marker. */
			public static function on_document_saved( $document ): void {
				unset( self::$mutation[ (int) $document->get_post()->ID ] );
			}
		}
		add_action( 'elementor/document/after_save', array( Editor_Sync_State::class, 'on_document_saved' ) );
		/** modules/mcp/utils/mcp-v4-gate.php:34-51; the experiment as a switch. */
		class Mcp_V4_Gate {
			public static $atomic_editor = true;
			public static function is_available( string $id ) {
				return self::$atomic_editor ? true : new \WP_Error( 'elementor_v4_required', 'This site needs the Atomic Editor turned on before this can be built.' );
			}
		}
	}
}

/* ================================================================= tests */

namespace {
	// The free core's own readers, not the harness's test adapter.
	remove_all_filters( 'ab_mcp_builder_adapters' );
	AB_MCP_Builders::reset();
	add_filter( 'ab_mcp_pro_builder_tool_enabled', static function ( $v, $n ) { return array_key_exists( $n, $GLOBALS['ab_tools_on'] ) ? $GLOBALS['ab_tools_on'][ $n ] : $v; }, 10, 2 );
	$GLOBALS['ab_tools_on'] = array( 'wp_update_builder_element' => true );
	$MAIN = 'main' === $GLOBALS['ab_scenario'];

	/* ---- the registered types, from Elementor's control definitions ---- */
	function ab_control( $name, $type, $default = '', $tab = 'content', array $more = array() ) {
		return array_merge( array( 'name' => $name, 'type' => $type, 'tab' => $tab, 'default' => $default ), $more );
	}
	/** Controls every widget has on its advanced tab (includes/widgets/common-base.php). */
	function ab_advanced() {
		return array(
			'_element_id'       => ab_control( '_element_id', 'text', '', 'advanced' ),
			'_css_classes'      => ab_control( '_css_classes', 'text', '', 'advanced' ),
			'custom_css'        => ab_control( 'custom_css', 'code', '', 'advanced' ),
			'custom_attributes' => ab_control( 'custom_attributes', 'textarea', '', 'advanced' ),
		);
	}
	function ab_url_default( $url = '' ) { return array( 'url' => $url, 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ); }
	function ab_type( $name, array $controls ) { return new \Elementor\Test_Element_Type( $name, array_merge( $controls, ab_advanced() ) ); }
	function ab_types() {
		return array(
			'container'   => new \Elementor\Test_Element_Type( 'container', array( 'html_tag' => ab_control( 'html_tag', 'select', 'div', 'layout' ) ) ),
			// includes/widgets/heading.php:181 title TEXTAREA, :197 link URL.
			'heading'     => ab_type( 'heading', array( 'title' => ab_control( 'title', 'textarea', 'Add Your Heading Text Here' ), 'link' => ab_control( 'link', 'url', ab_url_default() ), 'header_size' => ab_control( 'header_size', 'select', 'h2' ) ) ),
			// includes/widgets/text-editor.php:137 editor WYSIWYG.
			'text-editor' => ab_type( 'text-editor', array( 'editor' => ab_control( 'editor', 'wysiwyg', '<p>Lorem ipsum</p>' ) ) ),
			// includes/widgets/traits/button-trait.php:82 text, :96 link, button_css_id.
			'button'      => ab_type( 'button', array( 'text' => ab_control( 'text', 'text', 'Click here' ), 'link' => ab_control( 'link', 'url', ab_url_default( '#' ) ), 'button_css_id' => ab_control( 'button_css_id', 'text', '' ) ) ),
			// includes/widgets/image.php:130 image MEDIA, :172 caption, :206 link.
			'image'       => ab_type( 'image', array( 'image' => ab_control( 'image', 'media', array( 'url' => '', 'id' => '' ) ), 'link' => ab_control( 'link', 'url', ab_url_default() ) ) ),
			// includes/widgets/icon-list.php:177 icon_list REPEATER with :139 text, :166 link.
			'icon-list'   => ab_type(
				'icon-list',
				array(
					'icon_list' => ab_control(
						'icon_list',
						'repeater',
						array( array( 'text' => 'List Item #1' ), array( 'text' => 'List Item #2' ) ),
						'content',
						array( 'fields' => array( 'text' => array( 'name' => 'text', 'type' => 'text' ), 'selected_icon' => array( 'name' => 'selected_icon', 'type' => 'icons' ), 'link' => array( 'name' => 'link', 'type' => 'url' ) ) )
					),
				)
			),
			'html'        => ab_type( 'html', array( 'html' => ab_control( 'html', 'code', '' ) ) ),
			// Not Elementor's: a widget that registers attribute-like settings as
			// plain text controls on the content tab (B1 of the plan review).
			'attrs'       => ab_type( 'attrs', array( 'label' => ab_control( 'label', 'text', '' ), 'custom_attributes' => ab_control( 'custom_attributes', 'text', '' ), 'css_classes' => ab_control( 'css_classes', 'text', '' ), 'button_css_id' => ab_control( 'button_css_id', 'text', '' ), 'html_tag' => ab_control( 'html_tag', 'text', '' ) ) ),
			'e-flexbox'   => new \Elementor\Test_Element_Type( 'e-flexbox', array() ),
			'e-heading'   => new \Elementor\Test_Element_Type( 'e-heading', array() ),
			'e-paragraph' => new \Elementor\Test_Element_Type( 'e-paragraph', array() ),
			'e-button'    => new \Elementor\Test_Element_Type( 'e-button', array() ),
		);
	}
	function ab_load_elementor() {
		$plugin                    = new \Elementor\Plugin();
		$plugin->elements_manager  = new \Elementor\Test_Elements_Manager();
		$plugin->elements_manager->types = ab_types();
		$plugin->documents         = new \Elementor\Test_Documents_Manager();
		$plugin->db                = new \Elementor\Test_DB();
		\Elementor\Plugin::$instance = $plugin;
	}
	ab_load_elementor();

	/* ---- abilities (main scenario): manage-elements and publish-document ---- */
	/**
	 * The plain value of one setting as Elementor resolves it against the
	 * prop's schema (Plain_Values_Resolver): narrowed to the props the
	 * measured widgets have — text keeps its stored $$type, a link object
	 * and an image object become their wrapped forms (link-prop-type.php,
	 * image-prop-type.php, image-src-prop-type.php).
	 */
	function ab_wrap( $plain, $stored ) {
		if ( is_string( $plain ) ) {
			return array( '$$type' => isset( $stored['$$type'] ) ? $stored['$$type'] : 'escaped-html', 'value' => $plain );
		}
		if ( isset( $plain['destination'] ) ) {
			$v = array( 'destination' => array( '$$type' => 'url', 'value' => $plain['destination'] ) );
			if ( isset( $plain['isTargetBlank'] ) ) { $v['isTargetBlank'] = array( '$$type' => 'boolean', 'value' => (bool) $plain['isTargetBlank'] ); }
			if ( isset( $plain['tag'] ) ) { $v['tag'] = array( '$$type' => 'string', 'value' => $plain['tag'] ); }
			return array( '$$type' => 'link', 'value' => $v );
		}
		return array( '$$type' => 'image', 'value' => array( 'src' => array( '$$type' => 'image-src', 'value' => array( 'id' => array( '$$type' => 'image-attachment-id', 'value' => (int) $plain['src']['id'] ) ) ), 'size' => array( '$$type' => 'string', 'value' => $plain['size'] ) ) );
	}
	function ab_tree_update( array &$elements, $id, array $settings ) {
		foreach ( $elements as &$el ) {
			if ( isset( $el['id'] ) && $el['id'] === $id ) {
				foreach ( $settings as $k => $v ) {
					if ( null === $v ) { unset( $el['settings'][ $k ] ); continue; }
					$el['settings'][ $k ] = ab_wrap( $v, isset( $el['settings'][ $k ] ) ? $el['settings'][ $k ] : array() );
				}
				return true;
			}
			if ( ! empty( $el['elements'] ) && ab_tree_update( $el['elements'], $id, $settings ) ) {
				return true;
			}
		}
		return false;
	}
	/**
	 * modules/mcp/abilities/manage-elements-ability.php:143-281: the document
	 * or the account's autosave (:638-651), the update merged into the tree,
	 * saved preserving the live status (document-mutation-save.php), then
	 * Editor_Sync_State::set_mcp_mutation() (abstract-ability.php:27-52).
	 */
	function ab_manage_elements( $input ) {
		$id = (int) $input['post_id'];
		if ( isset( $GLOBALS['ab_ability_fail'] ) ) {
			return array( 'status' => 'error', 'results' => array( array( 'index' => 0, 'status' => 'error', 'code' => 'elementor_invalid_settings', 'message' => $GLOBALS['ab_ability_fail'] ) ), 'post_id' => $id, 'edit_url' => 'http://x/' );
		}
		$doc  = \Elementor\Plugin::$instance->documents->get_doc_or_auto_save( $id, get_current_user_id() );
		$tree = json_decode( (string) get_post_meta( $doc->get_post()->ID, '_elementor_data', true ), true );
		foreach ( $input['operations'] as $op ) {
			if ( ! ab_tree_update( $tree, $op['element_id'], $op['settings'] ) ) {
				return array( 'status' => 'error', 'results' => array( array( 'index' => 0, 'status' => 'error', 'code' => 'elementor_not_found', 'message' => 'Element not found.' ) ), 'post_id' => $id, 'edit_url' => 'http://x/' );
			}
		}
		$saved = \Elementor\Core\Utils\Document\Document_Mutator::instance()->save_as_draft( $doc, $tree, true );
		if ( is_wp_error( $saved ) ) {
			return array( 'status' => 'error', 'results' => array(), 'save_error' => $saved->get_error_message(), 'post_id' => $id, 'edit_url' => 'http://x/' );
		}
		\Elementor\Modules\Mcp\Utils\Editor_Sync_State::set_mcp_mutation( $id );
		return array( 'status' => 'success', 'results' => array( array( 'index' => 0, 'status' => 'success', 'action' => 'update' ) ), 'post_id' => $id, 'edit_url' => 'http://x/' );
	}
	/** modules/mcp/abilities/publish-document-ability.php:61-141. */
	function ab_publish_document( $input ) {
		$id = (int) $input['post_id'];
		if ( ! current_user_can( 'publish_post', $id ) ) {
			return new WP_Error( 'rest_cannot_publish', 'Sorry, you are not allowed to publish this document.' );
		}
		$prev = get_post_status( $id );
		$auto = wp_get_post_autosave( $id, get_current_user_id() );
		if ( $auto ) {
			ab_copy_elementor_meta( $auto->ID, $id );   // Revisions_Manager::restore_revision()
			wp_delete_post_revision( $auto->ID );
		}
		if ( 'publish' !== $prev ) {
			$GLOBALS['db_posts'][ $id ]['post_status'] = 'publish';
		}
		return array( 'post_id' => $id, 'status' => 'publish', 'previous_status' => $prev );
	}
	if ( $MAIN ) {
		$GLOBALS['ab_abilities'] = array(
			'elementor/manage-elements'  => new AB_Test_Ability( 'elementor/manage-elements', 'ab_manage_elements' ),
			'elementor/publish-document' => new AB_Test_Ability( 'elementor/publish-document', 'ab_publish_document' ),
		);
	}

	/* ---- pages ---- */
	// Measured: ergebnis-1-elementor-klassisch.json, elementor_meta and elementor_post_content.
	const AB_K_DATA    = '[{"id":"c0ffee1","elType":"container","settings":[],"elements":[{"id":"a1b2c3d","elType":"widget","settings":{"title":"Willkommen bei Alpine Bikes","header_size":"h1"},"elements":[],"widgetType":"heading"},{"id":"b2c3d4e","elType":"widget","settings":{"editor":"<p>\\u00d6ffnungszeiten: Mo\\u2013Fr 9\\u201318 Uhr<\\/p>"},"elements":[],"widgetType":"text-editor"},{"id":"c3d4e5f","elType":"widget","settings":{"text":"Jetzt reservieren","link":{"url":"https:\\/\\/example.com\\/reservieren","is_external":"","nofollow":""}},"elements":[],"widgetType":"button"}],"isInner":false}]';
	const AB_K_CONTENT = "<h1>Willkommen bei Alpine Bikes</h1>\t\t<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>\t\t\t\t\t\t\t\t<a href=\"https://example.com/reservieren\">\n\t\t\t\t\t\t\t\t\tJetzt reservieren\n\t\t\t\t\t</a>";
	// Measured: ergebnis-3-atomar-beaver-ersetzen.json, atomar_data and atomar_post_content.
	const AB_A_DATA    = '[{"id":"f00d001","elType":"e-flexbox","elements":[{"id":"f00d002","elType":"widget","settings":{"tag":{"$$type":"string","value":"h1"},"title":{"$$type":"escaped-html","value":"Willkommen bei Alpine Bikes"}},"elements":[],"widgetType":"e-heading","version":"0.0"},{"id":"f00d003","elType":"widget","settings":{"paragraph":{"$$type":"escaped-html","value":"\\u00d6ffnungszeiten: Mo\\u2013Fr 9\\u201318 Uhr"}},"elements":[],"widgetType":"e-paragraph","version":"0.0"},{"id":"f00d004","elType":"widget","settings":{"text":{"$$type":"escaped-html","value":"Jetzt reservieren"}},"elements":[],"widgetType":"e-button","version":"0.0"}],"isInner":false,"version":"0.0"}]';
	const AB_A_CONTENT = "<h1 data-interaction-id=\"f00d002\">Willkommen bei Alpine Bikes</h1>\n\t\t\t\t\t<p data-interaction-id=\"f00d003\">Öffnungszeiten: Mo–Fr 9–18 Uhr</p>\n\t\t\t\t\t<button data-interaction-id=\"f00d004\">\n\tJetzt reservieren\n</button>";

	/** An Elementor page: the raw tree, the meta Elementor stores with it (ergebnis-1 elementor_meta). */
	function ab_el_page( $id, $data, $content = '', $status = 'publish' ) {
		foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
			if ( $r[0] === (int) $id ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
		}
		ab_post( $id, array( 'post_content' => $content, 'post_status' => $status ) );
		ab_meta_add( $id, '_elementor_edit_mode', 'builder' );
		ab_meta_add( $id, '_elementor_template_type', 'wp-page' );
		ab_meta_add( $id, '_elementor_version', '4.3.2' );
		ab_meta_add( $id, '_elementor_data', is_string( $data ) ? $data : json_encode( $data ) );
		ab_meta_add( $id, '_elementor_css', 'a:1:{s:6:"status";s:4:"file";}' );
		unset( $GLOBALS['ab_autosaves'][ (int) $id ] );
		$GLOBALS['ab_caps'][ 'edit_post:' . $id ] = true;
		return get_post( $id );
	}
	function ab_widget( $id, $type, array $settings ) { return array( 'id' => $id, 'elType' => 'widget', 'settings' => $settings, 'elements' => array(), 'widgetType' => $type ); }
	function ab_box( $id, array $children ) { return array( 'id' => $id, 'elType' => 'container', 'settings' => array(), 'elements' => $children, 'isInner' => false ); }
	/** Not measured: a page built here from the control definitions above. */
	function ab_wide_page( $id = 20 ) {
		return ab_el_page(
			$id,
			array(
				ab_box(
					'cont20',
					array(
						ab_widget( 'h20', 'heading', array( 'title' => 'Hallo <span class="x">Welt</span>', 'link' => array( 'url' => 'https://example.com/a', 'is_external' => 'on', 'nofollow' => '', 'custom_attributes' => '' ) ) ),
						ab_widget( 'img20', 'image', array( 'image' => array( 'url' => 'http://example.com/u/alt.jpg', 'id' => 54, 'size' => '', 'alt' => 'alt', 'source' => 'library' ) ) ),
						ab_widget( 'il20', 'icon-list', array( 'icon_list' => array( array( '_id' => 'aa1', 'text' => 'Eins', 'link' => array( 'url' => '/eins' ) ), array( '_id' => 'aa2', 'text' => 'Zwei', 'link' => array( 'url' => '/zwei' ) ), array( '_id' => 'aa3', 'text' => 'Drei' ) ) ) ),
						ab_widget( 'il21', 'icon-list', array() ),
						ab_widget( 'html20', 'html', array( 'html' => '<div>example.com</div>' ) ),
						ab_widget( 'dyn20', 'heading', array( 'title' => 'Fallback', '__dynamic__' => array( 'title' => '[elementor-tag id="1" name="post-title"]' ) ) ),
						ab_widget( 'attr20', 'attrs', array( 'label' => 'Text', 'custom_attributes' => 'data-x|y', 'css_classes' => 'a', 'button_css_id' => 'b', 'html_tag' => 'div' ) ),
						array( 'elType' => 'widget', 'settings' => array( 'title' => 'Ohne Id' ), 'elements' => array(), 'widgetType' => 'heading' ),
						ab_widget( 'btnph', 'button', array( 'text' => 'Hallo {post_title}', 'custom_css' => 'selector a { background: url(https://example.com/x.png) }' ) ),
					)
				),
			)
		);
	}
	function ab_data( $id ) { $rows = ab_meta_rows( $id, '_elementor_data' ); return array() === $rows ? null : reset( $rows ); }
	function ab_tree( $id ) { return json_decode( (string) ab_data( $id ), true ); }
	function ab_find( $elements, $id ) {
		foreach ( $elements as $el ) {
			if ( isset( $el['id'] ) && $el['id'] === $id ) { return $el; }
			if ( ! empty( $el['elements'] ) ) { $f = ab_find( $el['elements'], $id ); if ( null !== $f ) { return $f; } }
		}
		return null;
	}
	function ab_setting( $post_id, $el, $key ) {
		$node = ab_find( ab_tree( $post_id ), $el );
		return null === $node || ! isset( $node['settings'][ $key ] ) ? null : $node['settings'][ $key ];
	}
	function ab_state( $id ) {
		$meta = array();
		foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
			if ( $r[0] === (int) $id ) { $meta[ $mid ] = $r; }
		}
		return array( $GLOBALS['db_posts'][ (int) $id ], $meta );
	}
	function ab_reset() {
		AB_MCP_Undo::clear_all();
		\Elementor\Core\Base\Document::$editable  = true;
		\Elementor\Core\Base\Document::$drop_also = array();
		if ( class_exists( '\Elementor\Modules\Mcp\Utils\Editor_Sync_State' ) ) {
			\Elementor\Modules\Mcp\Utils\Editor_Sync_State::$mutation = array();
			\Elementor\Modules\Mcp\Utils\Editor_Sync_State::$unsaved  = array();
			\Elementor\Modules\Mcp\Utils\Mcp_V4_Gate::$atomic_editor  = true;
		}
		unset( $GLOBALS['ab_ability_fail'] );
		$GLOBALS['ab_ability_calls'] = array();
		$GLOBALS['ab_events']        = array();
		$GLOBALS['ab_meta_deleted']  = array();
		$GLOBALS['ab_caps']['unfiltered_html'] = true;
		ab_load_elementor();
		AB_MCP_Builder_Writers::reset();
	}
	/** The stored page without the caches a write empties: the post row and every meta key that is not a cache. */
	function ab_content( $id ) {
		$s = ab_state( $id );
		foreach ( $s[1] as $mid => $r ) {
			if ( in_array( $r[1], array( '_elementor_css', '_elementor_element_cache', '_elementor_markdown_cache' ), true ) ) { unset( $s[1][ $mid ] ); }
		}
		return $s;
	}
	function ab_cause( $r ) { return is_wp_error( $r ) && is_array( $r->get_error_data() ) && isset( $r->get_error_data()['cause'] ) ? $r->get_error_data()['cause'] : ''; }
	function ab_calls( $name ) {
		$out = array();
		foreach ( $GLOBALS['ab_ability_calls'] as $c ) { if ( $name === $c[0] ) { $out[] = $c[1]; } }
		return $out;
	}
	function ab_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( is_array( $r ) ? 'ok' : gettype( $r ) ); }
	function ab_msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
	function ab_has( $haystack, $needle ) { return false !== strpos( is_array( $haystack ) ? implode( ' | ', $haystack ) : (string) $haystack, $needle ); }
	function ab_restore( $token ) {
		$c = AB_MCP_Undo::claim( $token );
		return AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
	}

	$GLOBALS['ab_caps'] = array( 'edit_posts' => true, 'read_post:55' => true, 'read_post:56' => true, 'unfiltered_html' => true, 'publish_post:30' => true );
	ab_post( 55, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );   // an image (ab_images)
	ab_post( 56, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );   // a PDF, say
	ab_meta_add( 55, '_wp_attachment_image_alt', 'Neues Bild' );

	$W = AB_MCP_Builder_Writers::for_adapter( 'elementor' );
	ok( $W instanceof AB_MCP_Builder_Writer_Elementor, 'E0 the Elementor writer registers itself from its file for the adapter id elementor' );

	/* ===================================================== old Elementor */

	if ( ! $MAIN ) {
		ab_reset();
		ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
		$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Hallo' ), array( 'apply' => true, 'publish' => false ) );
		ok( 'ab_mcp_builder_no_draft' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Leave publish out' ), 'O1 without Elementor\'s draft path (before 4.3) publish=false is refused with the way: ' . ab_msg( $r ) );
		ab_el_page( 30, AB_A_DATA, AB_A_CONTENT );
		$r = $W->set_fields( get_post( 30 ), 'f00d002', array( 'title' => 'Hallo' ), array() );
		ok( 'ab_mcp_builder_elementor_v4_unavailable' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Elementor editor' ) && ab_has( ab_msg( $r ), 'Elementor 4.3' ), 'O2 an atomic element without Elementor\'s abilities: read only, with the way' );
		$hash = AB_MCP_Builders::layout_hash( get_post( 10 ) );
		$r    = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Hallo' ), array( 'apply' => true, 'layout_hash' => $hash ) );
		ok( 'ok' === ab_code( $r ) && 'Hallo' === ab_setting( 10, 'a1b2c3d', 'title' ) && in_array( 'elementor', $r['caches']['builder_caches'], true ), 'O3 V3 writes as before, without the editor marker class: ' . ab_msg( $r ) );
		ab_result( 'BUILDER-ELEMENTOR (old)-CHECKS' );
	}

	/* ===================================================== A) the measured V3 page */

	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	ok( true === $W->can_write( get_post( 10 ) ) && in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( get_post( 10 ) ), true ), 'A1 on the measured page Elementor writes, and write_via names the tool' );
	\Elementor\Plugin::$instance = null;
	AB_MCP_Builders::reset();
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'x' ), array() );
	ok( 'ab_mcp_builder_elementor_not_loaded' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Elementor editor' ) && ! in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( get_post( 10 ) ), true ), 'A2 Elementor not loaded: only reading, the answer names the way, write_via leaves the tool out' );
	ab_reset();
	AB_MCP_Builders::reset();

	$s0   = ab_state( 10 );
	$hash = AB_MCP_Builders::layout_hash( get_post( 10 ) );
	$pre  = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Grüezi bei Alpine Bikes' ), array() );
	ok( 'ok' === ab_code( $pre ) && false === $pre['applied'] && 'Willkommen bei Alpine Bikes' === $pre['changes'][0]['before'] && 'Grüezi bei Alpine Bikes' === $pre['changes'][0]['after'] && $hash === $pre['layout_hash'], 'A3 preview: before as stored, after, the layout_hash: ' . ab_msg( $pre ) );
	ok( $s0 === ab_state( 10 ) && array() === get_option( AB_MCP_Undo::OPTION, array() ) && array() === $GLOBALS['ab_events'], 'A4 preview: nothing saved, no undo point' );

	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Grüezi bei Alpine Bikes' ), array( 'apply' => true, 'layout_hash' => $hash ) );
	ok( 'ok' === ab_code( $r ) && true === $r['applied'] && true === $r['published'] && true === $r['readback']['ok'], 'A5 applied, published, read back: ' . ab_msg( $r ) );
	ok( 'Grüezi bei Alpine Bikes' === ab_setting( 10, 'a1b2c3d', 'title' ) && 'h1' === ab_setting( 10, 'a1b2c3d', 'header_size' ), 'A6 the stored tree carries the new title and keeps the other settings' );
	$before_tree = json_decode( AB_K_DATA, true );
	$after_tree  = ab_tree( 10 );
	$after_tree[0]['elements'][0]['settings']['title'] = 'Willkommen bei Alpine Bikes';
	ok( $before_tree === $after_tree, 'A7 nothing else in the tree changed' );
	ok( array( 'save:10', 'mark:10' ) === $GLOBALS['ab_events'] && isset( \Elementor\Modules\Mcp\Utils\Editor_Sync_State::$mutation[10] ), 'A8 saved once through the document, then the editor marker is set (after the save, which would clear it): ' . implode( ',', $GLOBALS['ab_events'] ) );
	ok( AB_MCP_Undo::valid_token( $r['undo_token'] ) && in_array( '10:_elementor_element_cache', $GLOBALS['ab_meta_deleted'], true ) && array_key_exists( 'page_cache_purged', $r['caches'] ), 'A9 undo point, builder caches and page caches' );
	ok( '4.3.3' === ab_first_meta( 10, '_elementor_version' ) && $r['layout_hash'] === AB_MCP_Builders::layout_hash( get_post( 10 ) ), 'A10 Elementor stamped its version; the answer carries the new layout_hash' );
	ab_restore( $r['undo_token'] );
	ok( $s0[0]['post_content'] === get_post( 10 )->post_content && AB_K_DATA === ab_data( 10 ) && '4.3.2' === ab_first_meta( 10, '_elementor_version' ), 'A11 wp_undo puts back the tree, the text copy and the version byte for byte' );

	// text-editor: HTML, as post content may hold it.
	$r = $W->set_fields( get_post( 10 ), 'b2c3d4e', array( 'editor' => '<p>Öffnungszeiten: Mo–Sa <strong>9–17</strong> Uhr</p>' ), array( 'apply' => true ) );
	ok( 'ok' === ab_code( $r ) && '<p>Öffnungszeiten: Mo–Sa <strong>9–17</strong> Uhr</p>' === ab_setting( 10, 'b2c3d4e', 'editor' ), 'A12 text-editor takes simple HTML: ' . ab_msg( $r ) );
	$s1 = ab_state( 10 );
	$r  = $W->set_fields( get_post( 10 ), 'b2c3d4e', array( 'editor' => '<p onclick="x()">Hi</p>' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_kses' === ab_code( $r ) && $s1 === ab_state( 10 ), 'A13 markup kses would change is refused, nothing written' );

	// button: plain text, link with its other keys kept.
	$r = $W->set_fields( get_post( 10 ), 'c3d4e5f', array( 'text' => 'Jetzt buchen', 'link' => 'https://example.com/buchen' ), array( 'apply' => true ) );
	$link = ab_setting( 10, 'c3d4e5f', 'link' );
	ok( 'ok' === ab_code( $r ) && 'Jetzt buchen' === ab_setting( 10, 'c3d4e5f', 'text' ) && array( 'url' => 'https://example.com/buchen', 'is_external' => '', 'nofollow' => '' ) === $link, 'A14 button text and link; the link keeps is_external and nofollow: ' . ab_msg( $r ) );
	ok( 'ab_mcp_builder_url' === ab_code( $W->set_fields( get_post( 10 ), 'c3d4e5f', array( 'link' => 'javascript:alert(1)' ), array() ) ), 'A15 a javascript: link is refused' );
	ok( 'ab_mcp_builder_plain_text' === ab_code( $W->set_fields( get_post( 10 ), 'c3d4e5f', array( 'text' => 'Jetzt <b>buchen</b>' ), array() ) ), 'A16 a button label takes plain text only' );

	/* ===================================================== F) fields of the wider page */

	ab_reset();
	ab_wide_page();
	$r = $W->set_fields( get_post( 20 ), 'h20', array( 'title' => 'Servus <span class="x">Welt</span>' ), array( 'apply' => true ) );
	ok( 'ok' === ab_code( $r ) && 'Servus <span class="x">Welt</span>' === ab_setting( 20, 'h20', 'title' ), 'F1 a heading keeps the markup it already has: ' . ab_msg( $r ) );
	ok( 'ab_mcp_builder_plain_text' === ab_code( $W->set_fields( get_post( 20 ), 'h20', array( 'title' => 'Servus <b>Welt</b>' ), array() ) ), 'F2 and gets no new markup' );
	$r = $W->set_fields( get_post( 20 ), 'h20', array( 'link' => '/kontakt' ), array( 'apply' => true ) );
	ok( 'ok' === ab_code( $r ) && array( 'url' => '/kontakt', 'is_external' => 'on', 'nofollow' => '', 'custom_attributes' => '' ) === ab_setting( 20, 'h20', 'link' ), 'F3 a relative link; is_external stays on' );

	$r   = $W->set_fields( get_post( 20 ), 'img20', array( 'image_id' => 55 ), array( 'apply' => true ) );
	$img = ab_setting( 20, 'img20', 'image' );
	ok( 'ok' === ab_code( $r ) && 55 === $img['id'] && 'http://x/u/bild.jpg' === $img['url'] && 'Neues Bild' === $img['alt'] && 'library' === $img['source'] && '' === $img['size'], 'F4 image: id, URL of the attachment, its alt text, size kept: ' . ab_msg( $r ) . ' ' . json_encode( $img ) );
	ok( 'ab_mcp_builder_image' === ab_code( $W->set_fields( get_post( 20 ), 'img20', array( 'image' => 56 ), array() ) ), 'F5 an attachment that is no image is refused' );

	$r    = $W->set_fields( get_post( 20 ), 'il20', array( 'icon_list.1.text' => 'Zwo', 'icon_list.1.link' => 'https://example.com/zwo' ), array( 'apply' => true ) );
	$list = ab_setting( 20, 'il20', 'icon_list' );
	ok( 'ok' === ab_code( $r ) && 'Eins' === $list[0]['text'] && 'Zwo' === $list[1]['text'] && 'aa2' === $list[1]['_id'] && 'https://example.com/zwo' === $list[1]['link']['url'] && 'Drei' === $list[2]['text'], 'F6 repeater: the second item\'s text and link, the others and the item id kept: ' . ab_msg( $r ) );
	$r = $W->set_fields( get_post( 20 ), 'il20', array( 'icon_list.2.link' => '/drei' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_no_field' === ab_code( $r ), 'F7 a link the item does not store is not a field of the outline either' );
	$r = $W->set_fields( get_post( 20 ), 'il21', array( 'icon_list.0.text' => 'Erstes' ), array() );
	ok( 'ab_mcp_builder_elementor_default_list' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Elementor editor' ), 'F8 a list that is still Elementor\'s default is refused with the way: ' . ab_msg( $r ) );

	ok( 'ab_mcp_builder_locked' === ab_code( $W->set_fields( get_post( 20 ), 'html20', array( 'html' => 'x' ), array() ) ), 'F9 the HTML widget is locked' );
	$r = $W->set_fields( get_post( 20 ), 'dyn20', array( 'title' => 'x' ), array() );
	ok( 'ab_mcp_builder_no_field' === ab_code( $r ) && ab_has( ab_msg( $r ), 'dynamic value' ), 'F10 a field filled by a dynamic tag cannot be addressed, and the answer says why: ' . ab_msg( $r ) );
	foreach ( array( 'custom_attributes', 'css_classes', 'button_css_id', 'html_tag', '_element_id', '_css_classes', 'custom_css' ) as $name ) {
		ok( 'ab_mcp_builder_no_field' === ab_code( $W->set_fields( get_post( 20 ), 'attr20', array( $name => 'x' ), array() ) ), "F11 B1: $name is never a field, whatever its control type" );
	}
	ok( 'ok' === ab_code( $W->set_fields( get_post( 20 ), 'attr20', array( 'label' => 'Neu' ), array( 'apply' => true ) ) ) && 'Neu' === ab_setting( 20, 'attr20', 'label' ) && 'data-x|y' === ab_setting( 20, 'attr20', 'custom_attributes' ), 'F12 its visible label is, and the attribute stays as it was' );

	$r = $W->set_fields( get_post( 20 ), 'e0.7', array( 'title' => 'Mit Pfad' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_path_needs_hash' === ab_code( $r ), 'F13 an element without an id of its own is addressed by its position, which needs layout_hash' );
	$r = $W->set_fields( get_post( 20 ), 'e0.7', array( 'title' => 'Mit Pfad' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 20 ) ) ) );
	ok( 'ok' === ab_code( $r ) && 'Mit Pfad' === ab_tree( 20 )[0]['elements'][7]['settings']['title'], 'F14 with it, the element at that position changes' );

	$r = $W->set_fields( get_post( 20 ), 'btnph', array( 'text' => 'Hallo' ), array() );
	ok( 'ab_mcp_builder_placeholder' === ab_code( $r ), 'F15 a placeholder ({post_title}) has to stay' );
	ok( 'ok' === ab_code( $W->set_fields( get_post( 20 ), 'btnph', array( 'text' => 'Grüezi {post_title}' ), array( 'apply' => true ) ) ) && 'Grüezi {post_title}' === ab_setting( 20, 'btnph', 'text' ), 'F16 kept, the text around it changes' );

	// Two elements share an Elementor id: both are addressed by position.
	ab_el_page( 21, array( ab_box( 'cont21', array( ab_widget( 'dup1', 'heading', array( 'title' => 'Eins' ) ), ab_widget( 'dup1', 'heading', array( 'title' => 'Zwei' ) ) ) ) ) );
	$r = $W->set_fields( get_post( 21 ), 'e0.1', array( 'title' => 'Zwo' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 21 ) ) ) );
	$t = ab_tree( 21 );
	ok( 'ok' === ab_code( $r ) && 'Eins' === $t[0]['elements'][0]['settings']['title'] && 'Zwo' === $t[0]['elements'][1]['settings']['title'], 'F17 of two elements with the same id, the one at the position changes' );

	/* ===================================================== T) T2: every element registered and kept, before save() */

	ab_reset();
	ab_el_page( 40, array( ab_box( 'cont40', array( ab_widget( 'h40', 'heading', array( 'title' => 'Titel' ) ), ab_widget( 'pro40', 'form-pro', array( 'form_name' => 'Kontakt' ) ), ab_box( 'inner40', array( ab_widget( 'slides40', 'slides-pro', array() ) ) ) ) ) ) );
	$s0 = ab_state( 40 );
	$r  = $W->set_fields( get_post( 40 ), 'h40', array( 'title' => 'Neu' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_elementor_unregistered' === ab_code( $r ) && ab_has( ab_msg( $r ), 'form-pro' ) && ab_has( ab_msg( $r ), 'slides-pro' ) && ab_has( ab_msg( $r ), 'Activate the plugin' ), 'T1 a page with unregistered widgets (also nested) is refused, naming them and the way: ' . ab_msg( $r ) );
	ok( $s0 === ab_state( 40 ) && array() === $GLOBALS['ab_events'] && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'T2 before save(): nothing saved, no undo point' );
	ok( 'ab_mcp_builder_elementor_unregistered' === ab_code( $W->set_fields( get_post( 40 ), 'h40', array( 'title' => 'Neu' ), array() ) ), 'T3 the preview says so too' );

	// A widget that disappears between the check and the write (its plugin
	// deactivated in between): the guard in write() stops it before save().
	class AB_Test_Elementor_Late extends AB_MCP_Builder_Writer_Elementor {
		public static $before = null;
		protected function write( $post, array $change, array $opts ) {
			if ( is_callable( self::$before ) ) { $cb = self::$before; self::$before = null; $cb(); }
			return parent::write( $post, $change, $opts );
		}
	}
	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$s0                            = ab_state( 10 );
	AB_Test_Elementor_Late::$before = static function () { unset( \Elementor\Plugin::$instance->elements_manager->types['button'] ); };
	$late                          = new AB_Test_Elementor_Late();
	$r                             = $late->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Neu' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_elementor_unregistered' === ab_cause( $r ) && ab_has( ab_msg( $r ), 'button' ) && array() === $GLOBALS['ab_events'], 'T4 checked again in write(), right before save(): not saved: ' . ab_msg( $r ) );
	ok( $s0 === ab_state( 10 ) && array() === get_option( AB_MCP_Undo::OPTION, array() ) && ab_has( ab_msg( $r ), 'page is unchanged' ), 'T5 the page is unchanged and the empty undo point is gone' );

	// A loss the check cannot foresee (an element whose instance fails):
	// the read-back finds it and the page is put back.
	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$s0 = ab_state( 10 );
	\Elementor\Core\Base\Document::$drop_also = array( 'button' );
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Neu' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_readback_failed' === ab_code( $r ) && ab_has( ab_msg( $r ), 'put back' ) && true === $r->get_error_data()['rolled_back'], 'T6 an element lost in the save: read back, put back: ' . ab_msg( $r ) );
	ok( AB_K_DATA === ab_data( 10 ) && $s0[0]['post_content'] === get_post( 10 )->post_content, 'T7 byte for byte' );

	/* ===================================================== K) kses for accounts without unfiltered_html */

	ab_reset();
	ab_el_page( 41, array( ab_box( 'cont41', array( ab_widget( 'h41', 'heading', array( 'title' => 'Titel' ) ), ab_widget( 'te41', 'text-editor', array( 'editor' => '<p>Hi</p><script>track()</script>' ) ) ) ) ) );
	$GLOBALS['ab_caps']['unfiltered_html'] = false;
	$s0 = ab_state( 41 );
	$r  = $W->set_fields( get_post( 41 ), 'h41', array( 'title' => 'Neu' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_elementor_kses' === ab_code( $r ) && ab_has( ab_msg( $r ), 'te41' ) && ab_has( ab_msg( $r ), 'unfiltered_html' ) && $s0 === ab_state( 41 ) && array() === $GLOBALS['ab_events'], 'K1 without unfiltered_html, a page whose script Elementor would strip is refused, nothing saved: ' . ab_msg( $r ) );
	$GLOBALS['ab_caps']['unfiltered_html'] = true;
	$r = $W->set_fields( get_post( 41 ), 'h41', array( 'title' => 'Neu' ), array( 'apply' => true ) );
	ok( 'ok' === ab_code( $r ) && '<p>Hi</p><script>track()</script>' === ab_setting( 41, 'te41', 'editor' ), 'K2 with it, the change is written and the script stays' );
	$GLOBALS['ab_caps']['unfiltered_html'] = false;
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Berg & Tal' ), array( 'apply' => true ) );
	ok( 'ok' === ab_code( $r ) && true === $r['readback']['ok'] && 'Berg &amp; Tal' === ab_setting( 10, 'a1b2c3d', 'title' ), 'K3 a clean page is written without the capability (kses only normalises the "&"): ' . ab_msg( $r ) );
	$GLOBALS['ab_caps']['unfiltered_html'] = true;

	/* ===================================================== S) save() === false */

	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$s0 = ab_state( 10 );
	\Elementor\Core\Base\Document::$editable = false;
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Neu' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_elementor_not_saved' === ab_cause( $r ) && ab_has( ab_msg( $r ), 'Role Manager' ) && $s0 === ab_state( 10 ) && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'S1 save() returning false is a failure with the way; page unchanged, no undo point: ' . ab_msg( $r ) );

	/* ===================================================== E) Elementor's editor */

	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	\Elementor\Modules\Mcp\Utils\Editor_Sync_State::$unsaved[10] = true;
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Neu' ), array() );
	ok( 'ok' === ab_code( $r ) && ab_has( $r['warnings'], 'unsaved changes' ), 'E1 unsaved work in an open Elementor editor: the preview warns' );

	/* ===================================================== D) publish=false: Elementor's autosave */

	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$c0  = ab_content( 10 );
	$pre = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Entwurf' ), array( 'publish' => false ) );
	ok( 'ok' === ab_code( $pre ) && ab_has( $pre['notes'], 'autosave' ) && array() === ( isset( $GLOBALS['ab_autosaves'][10] ) ? $GLOBALS['ab_autosaves'][10] : array() ), 'D1 preview of publish=false: says where the change goes, creates nothing' );
	$r    = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Entwurf' ), array( 'apply' => true, 'publish' => false ) );
	$auto = wp_get_post_autosave( 10, get_current_user_id() );
	ok( 'ok' === ab_code( $r ) && false === $r['published'] && is_object( $auto ) && 'Entwurf' === ab_setting( $auto->ID, 'a1b2c3d', 'title' ), 'D2 publish=false: the change is in the account\'s autosave revision: ' . ab_msg( $r ) );
	ok( $c0 === ab_content( 10 ) && true === $r['readback']['ok'], 'D3 the live page is untouched; the read-back checked the autosave' );
	ok( null === $r['undo_token'] && array() === get_option( AB_MCP_Undo::OPTION, array() ) && ab_has( $r['notes'], 'revision #' . $auto->ID ) && $auto->ID === $r['draft'], 'D4 no undo point for a page that did not change; the answer names the draft' );
	ok( array() === $r['caches']['page_cache_purged'] && isset( \Elementor\Modules\Mcp\Utils\Editor_Sync_State::$mutation[10] ), 'D5 no page cache purge for a draft; the editor marker is set on the page' );
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Noch ein Entwurf' ), array( 'publish' => false ) );
	ok( 'ab_mcp_builder_elementor_autosave' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Elementor editor' ) && ab_has( ab_msg( $r ), 'Leaving publish out' ), 'D6 a draft that differs from the page already exists: refused with the way: ' . ab_msg( $r ) );
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Live' ), array( 'apply' => true ) );
	ok( 'ok' === ab_code( $r ) && 'Live' === ab_setting( 10, 'a1b2c3d', 'title' ) && ab_has( $r['warnings'], 'unpublished changes' ), 'D7 publish=true still writes the page, warning about the draft' );

	ab_reset();
	ab_el_page( 11, AB_K_DATA, AB_K_CONTENT, 'draft' );
	$r = $W->set_fields( get_post( 11 ), 'a1b2c3d', array( 'title' => 'Im Entwurf' ), array( 'apply' => true, 'publish' => false ) );
	ok( 'ok' === ab_code( $r ) && 'Im Entwurf' === ab_setting( 11, 'a1b2c3d', 'title' ) && AB_MCP_Undo::valid_token( $r['undo_token'] ) && ab_has( $r['notes'], 'not published' ) && array() === ( isset( $GLOBALS['ab_autosaves'][11] ) ? $GLOBALS['ab_autosaves'][11] : array() ), 'D8 a page that is not published is written in place, with an undo point: ' . ab_msg( $r ) );

	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$s0 = ab_state( 10 );
	\Elementor\Core\Base\Document::$drop_also = array( 'button' );
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Entwurf' ), array( 'apply' => true, 'publish' => false ) );
	ok( 'ab_mcp_builder_readback_failed' === ab_code( $r ) && ab_has( ab_msg( $r ), 'deleted again' ) && false === wp_get_post_autosave( 10, get_current_user_id() ) && $s0 === ab_state( 10 ), 'D9 a draft that does not read back is deleted again; the page is unchanged: ' . ab_msg( $r ) );

	// An autosave of the account that equals the page: publish=false writes
	// into it; a failed read-back puts its tree back.
	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$aid = ab_create_autosave( 10, get_current_user_id() );
	$a0  = ab_state( $aid );
	\Elementor\Core\Base\Document::$drop_also = array( 'button' );
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Entwurf' ), array( 'apply' => true, 'publish' => false ) );
	ok( 'ab_mcp_builder_readback_failed' === ab_code( $r ) && ab_has( ab_msg( $r ), 'put back as it was' ) && AB_K_DATA === ab_data( $aid ), 'D10 a draft that existed and does not read back gets its tree back: ' . ab_msg( $r ) );
	\Elementor\Core\Base\Document::$drop_also = array();
	$r = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Entwurf' ), array( 'apply' => true, 'publish' => false ) );
	ok( 'ok' === ab_code( $r ) && $aid === $r['draft'] && 'Entwurf' === ab_setting( $aid, 'a1b2c3d', 'title' ) && AB_K_DATA === ab_data( 10 ), 'D11 it is the draft the change goes into; the page stays as it is' );

	/* ===================================================== V) V4: Elementor's abilities */

	ab_reset();
	ab_el_page( 30, AB_A_DATA, AB_A_CONTENT );
	$s0  = ab_state( 30 );
	$pre = $W->set_fields( get_post( 30 ), 'f00d002', array( 'title' => 'Grüezi' ), array() );
	ok( 'ok' === ab_code( $pre ) && 'Willkommen bei Alpine Bikes' === $pre['changes'][0]['before'] && array() === $GLOBALS['ab_ability_calls'], 'V1 preview of an atomic element: before as stored, no ability called: ' . ab_msg( $pre ) );
	$r = $W->set_fields( get_post( 30 ), 'f00d002', array( 'title' => 'Grüezi' ), array( 'apply' => true, 'layout_hash' => $pre['layout_hash'] ) );
	ok( 'ok' === ab_code( $r ) && true === $r['readback']['ok'] && true === $r['published'], 'V2 applied and read back: ' . ab_msg( $r ) );
	$calls = $GLOBALS['ab_ability_calls'];
	ok( 2 === count( $calls ) && 'elementor/manage-elements' === $calls[0][0] && array( 'post_id' => 30, 'operations' => array( array( 'action' => 'update', 'element_id' => 'f00d002', 'settings' => array( 'title' => 'Grüezi' ) ) ) ) === $calls[0][1], 'V3 handed to elementor/manage-elements: post, element id, the plain value' );
	ok( 'elementor/publish-document' === $calls[1][0] && array( 'post_id' => 30 ) === $calls[1][1], 'V4 then promoted with elementor/publish-document' );
	ok( array( '$$type' => 'escaped-html', 'value' => 'Grüezi' ) === ab_setting( 30, 'f00d002', 'title' ) && array( '$$type' => 'string', 'value' => 'h1' ) === ab_setting( 30, 'f00d002', 'tag' ) && false === wp_get_post_autosave( 30, get_current_user_id() ), 'V5 the live page carries the new title as Elementor wraps it; no draft left' );
	ok( AB_MCP_Undo::valid_token( $r['undo_token'] ) && isset( \Elementor\Modules\Mcp\Utils\Editor_Sync_State::$mutation[30] ), 'V6 undo point and editor marker' );
	$pc = $GLOBALS['db_posts'][30]['post_content'];
	ok( ab_has( $pc, 'Grüezi' ) && ! ab_has( $pc, 'Willkommen bei Alpine Bikes' ) && in_array( 'plain:30', $GLOBALS['ab_events'], true ), 'V6a the text copy in post_content carries the new title too (publish-document copies only the meta): ' . substr( $pc, 0, 60 ) );
	ab_restore( $r['undo_token'] );
	ok( AB_A_DATA === ab_data( 30 ), 'V7 wp_undo puts the measured tree back byte for byte' );
	ok( AB_A_CONTENT === $GLOBALS['db_posts'][30]['post_content'], 'V7a and the measured text copy too' );

	ab_reset();
	ab_el_page( 30, AB_A_DATA, AB_A_CONTENT );
	\Elementor\Plugin::$instance->db->fail = true;
	$r = $W->set_fields( get_post( 30 ), 'f00d002', array( 'title' => 'Grüezi' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 30 ) ) ) );
	\Elementor\Plugin::$instance->db->fail = false;
	ok( 'ok' === ab_code( $r ) && true === $r['applied'] && array( '$$type' => 'escaped-html', 'value' => 'Grüezi' ) === ab_setting( 30, 'f00d002', 'title' ) && ab_has( $r['warnings'], 'post_content' ), 'V7b the text copy cannot be written: the published change stays, the answer says what is stale: ' . ab_msg( $r ) );

	ab_reset();
	ab_el_page( 30, AB_A_DATA, AB_A_CONTENT );
	\Elementor\Modules\Mcp\Utils\Mcp_V4_Gate::$atomic_editor = false;
	$r = $W->set_fields( get_post( 30 ), 'f00d002', array( 'title' => 'x' ), array() );
	ok( 'ab_mcp_builder_elementor_v4_unavailable' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Atomic Editor' ) && ab_has( ab_msg( $r ), 'Elementor › Settings' ) && array() === $GLOBALS['ab_ability_calls'], 'V8 Atomic Editor off: read only, the answer says how to switch it on' );
	\Elementor\Modules\Mcp\Utils\Mcp_V4_Gate::$atomic_editor = true;

	ab_el_page( 31, AB_A_DATA, AB_A_CONTENT, 'private' );
	$r = $W->set_fields( get_post( 31 ), 'f00d002', array( 'title' => 'x' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_elementor_private' === ab_code( $r ) && ab_has( ab_msg( $r ), 'publish=false' ) && 'private' === get_post_status( 31 ) && array() === $GLOBALS['ab_ability_calls'], 'V9 a private page is never made public by publishing: refused with the way' );
	$c0 = ab_content( 31 );
	$r  = $W->set_fields( get_post( 31 ), 'f00d002', array( 'title' => 'Privat' ), array( 'apply' => true, 'publish' => false ) );
	$a  = wp_get_post_autosave( 31, get_current_user_id() );
	ok( 'ok' === ab_code( $r ) && $c0 === ab_content( 31 ) && is_object( $a ) && 'Privat' === ab_setting( $a->ID, 'f00d002', 'title' )['value'] && null === $r['undo_token'], 'V10 publish=false: Elementor\'s draft carries it, the page does not, no undo point: ' . ab_msg( $r ) );

	ab_reset();
	ab_el_page( 30, AB_A_DATA, AB_A_CONTENT );
	$s0                          = ab_state( 30 );
	$GLOBALS['ab_ability_fail'] = 'Settings validation failed. title: invalid_value';
	$r                           = $W->set_fields( get_post( 30 ), 'f00d002', array( 'title' => 'x' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_elementor_refused' === ab_cause( $r ) && ab_has( ab_msg( $r ), 'Settings validation failed' ) && $s0 === ab_state( 30 ) && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'V11 Elementor refuses: its message, page unchanged, no undo point: ' . ab_msg( $r ) );
	unset( $GLOBALS['ab_ability_fail'] );

	ab_el_page( 32, array( array( 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array( ab_widget( 'eb1', 'e-button', array( 'text' => array( '$$type' => 'escaped-html', 'value' => 'Los' ), 'link' => array( '$$type' => 'link', 'value' => array( 'destination' => array( '$$type' => 'url', 'value' => 'https://example.com/' ), 'isTargetBlank' => array( '$$type' => 'boolean', 'value' => true ), 'tag' => array( '$$type' => 'string', 'value' => 'a' ) ) ) ) ) ) ) ) );
	$GLOBALS['ab_caps']['publish_post:32'] = true;
	$GLOBALS['ab_ability_calls']          = array();
	$r = $W->set_fields( get_post( 32 ), 'eb1', array( 'link' => 'https://example.org/neu' ), array( 'apply' => true ) );
	$l = ab_setting( 32, 'eb1', 'link' );
	$me = ab_calls( 'elementor/manage-elements' );
	ok( 'ok' === ab_code( $r ) && 1 === count( $me ) && array( 'destination' => 'https://example.org/neu', 'isTargetBlank' => true, 'tag' => 'a' ) === $me[0]['operations'][0]['settings']['link'], 'V12 a link goes to Elementor with the new destination and what it had besides: ' . ab_msg( $r ) );
	ok( 'https://example.org/neu' === $l['value']['destination']['value'] && true === $l['value']['isTargetBlank']['value'] && ab_has( $r['warnings'], 'not measured' ), 'V13 stored as Elementor wraps it; the answer says the form is not measured' );
	$GLOBALS['ab_ability_calls'] = array();
	$GLOBALS['ab_caps']['publish_post:32'] = false;
	$r = $W->set_fields( get_post( 32 ), 'eb1', array( 'text' => 'x' ), array( 'apply' => true ) );
	ok( 'ab_mcp_forbidden' === ab_code( $r ) && ab_has( ab_msg( $r ), 'publish=false' ) && array() === $GLOBALS['ab_ability_calls'], 'V14 an account that may not publish the page: refused before anything, with the way' );

	ab_el_page( 33, array( array( 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array( ab_widget( 'eh1', 'e-heading', array( 'title' => array( '$$type' => 'overridable', 'value' => array( 'override_key' => 'k1', 'origin_value' => array( '$$type' => 'escaped-html', 'value' => 'Geteilt' ) ) ) ) ) ) ) ) );
	$GLOBALS['ab_caps']['publish_post:33'] = true;
	$r = $W->set_fields( get_post( 33 ), 'eh1', array( 'title' => 'x' ), array() );
	ok( 'ab_mcp_builder_elementor_component' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Elementor editor' ), 'V15 a component override is refused with the way: ' . ab_msg( $r ) );

	ab_el_page( 34, array( array( 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array( ab_widget( 'eh1', 'e-heading', array( 'title' => array( '$$type' => 'escaped-html', 'value' => 'A' ) ) ), array( 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array( 'title' => array( '$$type' => 'escaped-html', 'value' => 'B' ) ), 'elements' => array() ) ) ) ) );
	$GLOBALS['ab_caps']['publish_post:34'] = true;
	$r = $W->set_fields( get_post( 34 ), 'e0.1', array( 'title' => 'x' ), array( 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 34 ) ) ) );
	ok( 'ab_mcp_builder_elementor_no_id' === ab_code( $r ), 'V16 an atomic element without an id of its own cannot be handed to Elementor' );

	ab_el_page( 35, array( array( 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array( ab_widget( 'eh1', 'e-heading', array( 'title' => array( '$$type' => 'escaped-html', 'value' => 'A' ) ) ), ab_widget( 'pro1', 'e-pro-only', array() ) ) ) ) );
	$GLOBALS['ab_caps']['publish_post:35'] = true;
	$GLOBALS['ab_ability_calls']          = array();
	$r = $W->set_fields( get_post( 35 ), 'eh1', array( 'title' => 'x' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_elementor_unregistered' === ab_code( $r ) && array() === $GLOBALS['ab_ability_calls'], 'V17 T2 holds for Elementor\'s own save too: an unregistered element stops the call before the ability' );

	// (Free: the section on Pro's ability switches is left out; see etappe-b/port_tests.py.)

	/* ===================================================== L) a locked element names its way */

	ab_reset();
	// A widget of an add-on that is inactive here (the measured eael-dual-color-header of el/seed-v3.php, add-on off).
	ab_el_page( 36, array( ab_box( 'cont36', array( ab_widget( 'hd36', 'heading', array( 'title' => 'Da' ) ), ab_widget( '3a4b5c6', 'eael-dual-color-header', array( 'eael_dch_first_title' => 'Zwei' ) ) ) ) ) );
	$r = $W->set_fields( get_post( 36 ), '3a4b5c6', array( 'eael_dch_first_title' => 'X' ), array() );
	ok( 'ab_mcp_builder_locked' === ab_code( $r ) && ab_has( ab_msg( $r ), 'eael-dual-color-header' ) && ab_has( ab_msg( $r ), 'Activate that plugin' ) && ab_has( ab_msg( $r ), 'do not save this page in the Elementor editor' ) && ! ab_has( ab_msg( $r ), 'Change it in the Elementor editor' ), 'L1 an unregistered widget: the way is its plugin, and not a save in the Elementor editor (which drops it): ' . ab_msg( $r ) );
	ab_wide_page();
	$r = $W->set_fields( get_post( 20 ), 'html20', array( 'html' => 'x' ), array() );
	ok( 'ab_mcp_builder_locked' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Change it in the Elementor editor in wp-admin' ), 'L2 an HTML widget keeps its way: the Elementor editor: ' . ab_msg( $r ) );

	// Atomic Editor off: Elementor registers no atomic element, the free reader locks the
	// container (unknown element type) and lists none of its children.
	ab_el_page( 37, AB_A_DATA, AB_A_CONTENT );
	$GLOBALS['ab_caps']['publish_post:37'] = true;
	foreach ( array( 'e-flexbox', 'e-heading', 'e-paragraph', 'e-button' ) as $t ) { unset( \Elementor\Plugin::$instance->elements_manager->types[ $t ] ); }
	\Elementor\Modules\Mcp\Utils\Mcp_V4_Gate::$atomic_editor = false;
	$r = $W->set_fields( get_post( 37 ), 'f00d002', array( 'title' => 'x' ), array() );
	ok( 'ab_mcp_builder_locked' === ab_code( $r ) && ab_has( ab_msg( $r ), 'inside element f00d001' ) && ab_has( ab_msg( $r ), 'Atomic Editor' ) && ab_has( ab_msg( $r ), 'Elementor › Settings' ) && 'f00d001' === $r->get_error_data()['inside'], 'L3 a child of the locked atomic container: not "no such element", but the container and how to switch the Atomic Editor on: ' . ab_msg( $r ) );
	$r = $W->set_fields( get_post( 37 ), 'f00d001', array( 'title' => 'x' ), array() );
	ok( 'ab_mcp_builder_locked' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Atomic Editor' ) && ab_has( ab_msg( $r ), 'do not save this page in the Elementor editor' ), 'L4 the container itself: the same way: ' . ab_msg( $r ) );
	$r = $W->set_fields( get_post( 37 ), 'zz404', array( 'title' => 'x' ), array() );
	ok( 'ab_mcp_builder_no_element' === ab_code( $r ), 'L5 an id the page does not have at all stays "no such element"' );
	\Elementor\Modules\Mcp\Utils\Mcp_V4_Gate::$atomic_editor = true;
	$r = $W->set_fields( get_post( 37 ), 'f00d001', array( 'title' => 'x' ), array() );
	ok( 'ab_mcp_builder_locked' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Activate that plugin' ) && ! ab_has( ab_msg( $r ), 'Atomic Editor' ), 'L6 an atomic type missing while the Atomic Editor is on: the plugin that provides it is the way: ' . ab_msg( $r ) );
	ab_load_elementor();

	/* ===================================================== B) B1: add-on widgets read only */

	ab_reset();
	$addon_file = $GLOBALS['ab_upload'] . '/addon/class-addon-widget.php';
	wp_mkdir_p( dirname( $addon_file ) );
	file_put_contents( $addon_file, '<?php class AB_Test_Addon_Widget { public function get_controls( $id = null ) { return array( "headline" => array( "name" => "headline", "type" => "text", "tab" => "content", "default" => "" ) ); } }' );
	require $addon_file;
	\Elementor\Plugin::$instance->elements_manager->types['addon-box'] = new AB_Test_Addon_Widget();
	ab_el_page( 42, array( ab_box( 'cont42', array( ab_widget( 'ad42', 'addon-box', array( 'headline' => 'Angebot' ) ) ) ) ) );
	$r = $W->set_fields( get_post( 42 ), 'ad42', array( 'headline' => 'Neu' ), array( 'apply' => true ) );
	ok( 'ab_mcp_builder_elementor_addon' === ab_code( $r ) && ab_has( ab_msg( $r ), 'Elementor editor' ) && ab_has( ab_msg( $r ), 'ab_mcp_pro_elementor_writable_widget' ) && 'Angebot' === ab_setting( 42, 'ad42', 'headline' ), 'B1 a widget whose class is not Elementor\'s: read only, with the way: ' . ab_msg( $r ) );
	add_filter( 'ab_mcp_pro_elementor_writable_widget', static function ( $ok, $type ) { return $ok || 'addon-box' === $type; }, 10, 2 );
	$r = $W->set_fields( get_post( 42 ), 'ad42', array( 'headline' => 'Neu' ), array( 'apply' => true ) );
	ok( 'ok' === ab_code( $r ) && 'Neu' === ab_setting( 42, 'ad42', 'headline' ), 'B2 opened by the filter, it is written' );
	remove_all_filters( 'ab_mcp_pro_elementor_writable_widget' );

	/* ===================================================== R) replace(), scope text */

	ab_reset();
	ab_wide_page();
	$s0  = ab_state( 20 );
	$pre = $W->replace( get_post( 20 ), 'example.com', 'example.org', array() );
	ok( 'ok' === ab_code( $pre ) && false === $pre['applied'] && $s0 === ab_state( 20 ), 'R1 preview of a replace: nothing written: ' . ab_msg( $pre ) );
	$fields = array();
	foreach ( $pre['changes'] as $c ) { $fields[] = $c['element'] . '.' . $c['field']; }
	sort( $fields );
	ok( array( 'h20.link' ) === $fields, 'R2 only the allowed fields that hold the text: ' . implode( ', ', $fields ) );
	ok( 3 === $pre['locked_matches'] && ab_has( $pre['notes'], 'scope: raw' ), 'R3 the matches in the HTML widget, the image address and the custom CSS are counted as locked_matches (' . $pre['locked_matches'] . '), with the way' );
	$r = $W->replace( get_post( 20 ), 'example.com', 'example.org', array( 'apply' => true, 'undo_tool' => 'wp_search_replace' ) );
	ok( 'ok' === ab_code( $r ) && 'https://example.org/a' === ab_setting( 20, 'h20', 'link' )['url'] && '<div>example.com</div>' === ab_setting( 20, 'html20', 'html' ) && 'http://example.com/u/alt.jpg' === ab_setting( 20, 'img20', 'image' )['url'] && false !== strpos( ab_setting( 20, 'btnph', 'custom_css' ), 'example.com' ), 'R4 applied: the link changed; HTML widget, image address and custom CSS untouched: ' . ab_msg( $r ) );
	ok( true === $r['readback']['ok'] && 'text' === AB_MCP_Undo::claim( $r['undo_token'] )['data']['scope'] && 1 === count( array_filter( $GLOBALS['ab_events'], static function ( $e ) { return 0 === strpos( $e, 'save:' ); } ) ), 'R5 one save, read back, undo point of scope text' );
	$r = $W->replace( get_post( 20 ), 'track()', 'x', array() );
	ok( 'ab_mcp_builder_nothing' === ab_code( $r ), 'R6 a text in no writable field: nothing to change' );
	$r = $W->replace( get_post( 20 ), 'example.com', 'x', array() );
	ok( 'ab_mcp_builder_nothing' === ab_code( $r ) && 3 === $r->get_error_data()['locked_matches'] && ab_has( ab_msg( $r ), 'scope: raw' ), 'R7 only locked matches left: the answer counts them and names scope: raw' );
	$r = $W->replace( get_post( 20 ), '{post_title}', 'Seite', array() );
	ok( 'ab_mcp_builder_nothing' === ab_code( $r ) && ab_has( ab_msg( $r ), 'btnph.text left as it is' ), 'R8 a replace that would take out a placeholder leaves that field and says so' );
	$r = $W->replace( get_post( 20 ), 'Zwei', 'Zwo', array( 'apply' => true ) );
	ok( 'ok' === ab_code( $r ) && 'Zwo' === ab_setting( 20, 'il20', 'icon_list' )[1]['text'], 'R9 repeater items are replaced in too' );
	$r = $W->replace( get_post( 20 ), '{post_title}', 'Seite', array() );
	ok( 'ab_mcp_builder_nothing' === ab_code( $r ) && 1 === $r->get_error_data()['locked_matches'] && array( 'btnph.text left as it is' ) === array_map( static function ( $l ) { return substr( $l, 0, 24 ); }, $r->get_error_data()['left'] ), 'R10 the field left for a reason is handed on in left (wp_search_replace names it) and its match counts as locked' );

	/* ============================================ N) a field set to the value it has */

	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$s0  = ab_content( 10 );
	$r   = $W->set_fields( get_post( 10 ), 'a1b2c3d', array( 'title' => 'Willkommen bei Alpine Bikes' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 10 ) ) ) );
	ok( 'ab_mcp_builder_nothing' === ab_code( $r ) && ab_has( ab_msg( $r ), 'already hold these values' ), 'N1 a heading set to the text it has: said so, as the other writers do' );
	ok( array() === $GLOBALS['ab_events'] && $s0 === ab_content( 10 ) && '4.3.2' === ab_first_meta( 10, '_elementor_version' ) && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'N2 no save (Document::save() would stamp the version and rebuild the copy), no undo point: the page stays byte for byte' );
	$r = $W->set_fields( get_post( 10 ), 'c3d4e5f', array( 'text' => 'Jetzt reservieren', 'url' => 'https://example.com/reservieren' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 10 ) ) ) );
	ok( 'ab_mcp_builder_nothing' === ab_code( $r ) && array() === $GLOBALS['ab_events'], 'N3 a button whose label and link already hold these values: no save' );
	$r = $W->set_fields( get_post( 10 ), 'c3d4e5f', array( 'text' => 'Jetzt buchen', 'url' => 'https://example.com/reservieren' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 10 ) ) ) );
	ok( 'ok' === ab_code( $r ) && 1 === count( $r['changes'] ) && 'text' === $r['changes'][0]['field'] && 'Jetzt buchen' === ab_setting( 10, 'c3d4e5f', 'text' ), 'N4 one field changed, one already as asked: only the changed one is written' );

	/* ===================================================== W) the tool */

	if ( ! class_exists( 'AB_MCP_Tools_Base', false ) ) {
		abstract class AB_MCP_Tools_Base {
			protected static function s( $a, $k, $d = '' ) { return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $d; }
			protected static function i( $a, $k, $d = 0 ) { return isset( $a[ $k ] ) ? (int) $a[ $k ] : $d; }
			protected static function b( $a, $k, $d = false ) { return isset( $a[ $k ] ) ? (bool) $a[ $k ] : $d; }
			protected static function need( $a, array $keys ) {
				foreach ( $keys as $k ) {
					if ( ! isset( $a[ $k ] ) || '' === $a[ $k ] ) { return new WP_Error( 'ab_mcp_missing_arg', 'Missing required argument(s): ' . $k ); }
				}
				return true;
			}
		}
	}
	require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-builder-write.php';
	ab_reset();
	ab_el_page( 10, AB_K_DATA, AB_K_CONTENT );
	$t = AB_MCP_Tools_Builder_Write::update_builder_element( array( 'id' => 10, 'element_id' => 'c3d4e5f', 'fields' => array( 'text' => 'Buchen' ) ) );
	ok( is_array( $t ) && 'elementor' === $t['builder'] && 'button' === $t['element']['type'] && array( 'text' => 'Jetzt reservieren' ) === $t['element']['before'] && false === $t['applied'], 'W1 wp_update_builder_element on an Elementor page: preview with type, before and after' );
	$t = AB_MCP_Tools_Builder_Write::update_builder_element( array( 'id' => 10, 'element_id' => 'c3d4e5f', 'fields' => (object) array( 'text' => 'Buchen' ), 'apply' => true, 'layout_hash' => $t['layout_hash'] ) );
	ok( is_array( $t ) && true === $t['applied'] && 'Buchen' === ab_setting( 10, 'c3d4e5f', 'text' ) && AB_MCP_Undo::valid_token( $t['undo_token'] ), 'W2 and applied, with an undo token' );
	ok( ! array_key_exists( 'draft', $t ), 'W3 a live write names no draft' );
	$t = AB_MCP_Tools_Builder_Write::update_builder_element( array( 'id' => 10, 'element_id' => 'a1b2c3d', 'fields' => array( 'title' => 'Entwurfstitel' ), 'apply' => true, 'publish' => false, 'layout_hash' => $t['layout_hash'] ) );
	$a = wp_get_post_autosave( 10, get_current_user_id() );
	ok( is_array( $t ) && true === $t['applied'] && false === $t['published'] && null === $t['undo_token'] && is_object( $a ) && $a->ID === $t['draft'] && 'Entwurfstitel' === ab_setting( $a->ID, 'a1b2c3d', 'title' ), 'W4 publish=false: the answer names the draft revision in draft, machine-readable' );

	ab_result( 'BUILDER-ELEMENTOR-CHECKS' );
}
