<?php
/**
 * Registry of the page-builder writers, one per reading adapter id, and what
 * the free core learns from it: which tools write a page (write_via).
 *
 * A writer joins in one of two ways, neither of which touches this file:
 * - a file in includes/builders/writers/ that calls register_class() with
 *   its class name (loaded by ab_mcp_pro_load_builders()); a class that
 *   writes several adapter ids (one per shortcode builder, as the free core
 *   reads them) has a static instances() that returns one writer per id;
 * - the filter ab_mcp_pro_builder_writers, for third parties.
 * A writer registered later for the same id replaces the earlier one.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Writers
 */
final class AB_MCP_Builder_Writers {

	/** The tool that writes elements. */
	const TOOL = 'wp_update_builder_element';

	/**
	 * Class names registered by the writer files, in load order.
	 *
	 * @var string[]
	 */
	private static $classes = array();

	/**
	 * Writers by adapter id, for this request.
	 *
	 * @var array<string,AB_MCP_Builder_Writer>|null
	 */
	private static $writers = null;

	/**
	 * Register a writer class (from its own file).
	 *
	 * @param string $class Class extending AB_MCP_Builder_Writer.
	 */
	public static function register_class( $class ) {
		$class = (string) $class;
		if ( '' !== $class && ! in_array( $class, self::$classes, true ) ) {
			self::$classes[] = $class;
			self::$writers   = null;
		}
	}

	/**
	 * Forget the writers built for this request (tests, late registration).
	 */
	public static function reset() {
		self::$writers = null;
	}

	/**
	 * Every writer, by the adapter id it writes.
	 *
	 * @return array<string,AB_MCP_Builder_Writer>
	 */
	public static function all() {
		if ( null !== self::$writers ) {
			return self::$writers;
		}
		$list = array();
		foreach ( self::$classes as $class ) {
			if ( ! class_exists( $class ) || ! is_subclass_of( $class, 'AB_MCP_Builder_Writer' ) ) {
				continue;
			}
			if ( method_exists( $class, 'instances' ) ) {
				foreach ( (array) call_user_func( array( $class, 'instances' ) ) as $writer ) {
					$list[] = $writer;
				}
				continue;
			}
			$list[] = new $class();
		}
		/**
		 * The page-builder writers of AlphaBridge MCP Pro. A writer extends
		 * AB_MCP_Builder_Writer and writes the pages of the reading
		 * adapter whose id() it shares.
		 *
		 * @param AB_MCP_Builder_Writer[] $list Writers, in order.
		 */
		$list = apply_filters( 'ab_mcp_pro_builder_writers', $list );
		$out  = array();
		foreach ( (array) $list as $writer ) {
			if ( $writer instanceof AB_MCP_Builder_Writer && '' !== $writer->id() ) {
				$out[ $writer->id() ] = $writer;
			}
		}
		self::$writers = $out;
		return $out;
	}

	/**
	 * The writer for a reading adapter id, or null.
	 *
	 * @param string $adapter_id Adapter id.
	 * @return AB_MCP_Builder_Writer|null
	 */
	public static function for_adapter( $adapter_id ) {
		$all = self::all();
		return isset( $all[ (string) $adapter_id ] ) ? $all[ (string) $adapter_id ] : null;
	}

	/**
	 * The writer for what the site shows of this post: the writer of the
	 * adapter AB_MCP_Builders::for_post() reads it with, or null.
	 *
	 * @param WP_Post $post Post.
	 * @return AB_MCP_Builder_Writer|null
	 */
	public static function for_post( $post ) {
		$adapter = AB_MCP_Builders::for_post( $post );
		return null === $adapter ? null : self::for_adapter( $adapter->id() );
	}

	/**
	 * Filter ab_mcp_builder_write_via: name wp_update_builder_element where
	 * it is switched on and a writer can write this page now.
	 *
	 * @param string[]   $tools   Tools so far.
	 * @param WP_Post    $post    Post.
	 * @param array|null $primary Builder answering for the page.
	 * @return string[]
	 */
	public static function write_via( $tools, $post, $primary = null ) {
		$tools = is_array( $tools ) ? $tools : array();
		if ( ! is_object( $post ) || ! self::tool_enabled( self::TOOL ) ) {
			return $tools;
		}
		try {
			$writer = self::for_post( $post );
			if ( null !== $writer && true === $writer->can_write( $post ) ) {
				$tools[] = self::TOOL;
			}
		} catch ( \Throwable $e ) {
			// A writer that cannot answer does not write this page.
			unset( $e );
		}
		return $tools;
	}

	/**
	 * Whether a tool is registered and switched on here.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public static function tool_enabled( $name ) {
		/**
		 * Short-circuits the question whether a Pro builder tool is switched
		 * on (tests; a site that wires its registry differently).
		 *
		 * @param bool|null $enabled Null to ask the registry.
		 * @param string    $name    Tool name.
		 */
		$pre = apply_filters( 'ab_mcp_pro_builder_tool_enabled', null, (string) $name );
		if ( null !== $pre ) {
			return (bool) $pre;
		}
		if ( ! class_exists( 'AB_MCP_Plugin', false ) || ! class_exists( 'AB_MCP_Settings', false ) ) {
			return false;
		}
		$plugin = AB_MCP_Plugin::instance();
		if ( ! is_object( $plugin ) || ! isset( $plugin->registry ) || ! is_object( $plugin->registry ) ) {
			return false;
		}
		$def = $plugin->registry->get( (string) $name );
		return is_array( $def ) && AB_MCP_Settings::is_tool_enabled( (string) $name, $def );
	}

	/**
	 * The answer where no writer writes this page: which builder, why only
	 * reading works, and what does work instead.
	 *
	 * @param WP_Post                     $post    Post.
	 * @param AB_MCP_Builder_Adapter|null $adapter Reading adapter, if any.
	 * @return WP_Error
	 */
	public static function no_writer_error( $post, $adapter ) {
		$primary = AB_MCP_Builders::primary( $post );
		$name    = null !== $adapter ? $adapter->name() : ( null !== $primary ? (string) $primary['name'] : __( 'this page builder', 'alphabridge-mcp' ) );
		$storage = AB_MCP_Builders::effective_storage( $post );
		if ( null === $adapter ) {
			/* translators: %s: page builder name */
			$msg = sprintf( __( 'AlphaBridge recognises %s on this page but has no reader for it yet, so its elements have no ids to address.', 'alphabridge-mcp' ), $name );
		} else {
			/* translators: %s: page builder name */
			$msg = sprintf( __( 'AlphaBridge reads %s pages (wp_get_builder_layout) but has no writer for them in this version, so it does not change their elements.', 'alphabridge-mcp' ), $name );
		}
		if ( 'A' === $storage ) {
			$msg .= ' ' . __( 'This page shows post_content, so wp_update_post changes it: read it with wp_get_post, change the text in content and keep the block markup as it is.', 'alphabridge-mcp' );
		} elseif ( 'A2' === $storage ) {
			/* translators: %s: page builder name */
			$msg .= ' ' . sprintf( __( 'wp_update_post changes what the page shows now, but %s restores its own copy the next time the page is saved there; for a lasting change use its editor in wp-admin.', 'alphabridge-mcp' ), $name );
		} else {
			/* translators: %s: page builder name */
			$msg .= ' ' . sprintf( __( 'Change it in the %s editor in wp-admin.', 'alphabridge-mcp' ), $name );
		}
		if ( self::tool_enabled( 'wp_search_replace' ) ) {
			$msg .= ' ' . __( 'A text that appears on many pages can also be replaced with wp_search_replace (run it as a dry run first).', 'alphabridge-mcp' );
		}
		return new WP_Error(
			'ab_mcp_builder_no_writer',
			$msg,
			array(
				'builder' => null !== $adapter ? $adapter->id() : ( null !== $primary ? (string) $primary['id'] : '' ),
				'storage' => $storage,
			)
		);
	}
}
