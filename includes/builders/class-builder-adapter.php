<?php
/**
 * Base class of a page-builder adapter: one builder's (or one family's) way of
 * storing a page, read as a compact outline.
 *
 * The contract between the free plugin (reading) and Pro (writing) is
 * AB_MCP_BUILDER_API (see class-builders.php). An adapter only needs code
 * where a builder needs code; block libraries and shortcode builders are
 * profiles (plain arrays) read by one shared adapter each.
 *
 * Signatures, kept on purpose:
 * - Parameters that take a post are untyped (documented as WP_Post), as
 *   everywhere in this plugin, so a subclass must not add a type to them —
 *   PHP would refuse the narrower declaration.
 * - Return types are declared; a subclass has to declare the same (or a
 *   narrower) one.
 *
 * Defaults: everything except id() and outline() has a default that reads the
 * builder's entry in signatures.php, so an adapter for a builder that is
 * already listed there usually overrides little more than outline().
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Adapter
 */
abstract class AB_MCP_Builder_Adapter {

	/**
	 * Stable key, e.g. 'elementor', 'blocks', 'beaver', 'shortcodes'.
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * The page as a list of elements in page order, see the element format in
	 * AB_MCP_Builders. Implementations honour these options:
	 * - include_locked (bool): list locked elements (without content) or leave them out;
	 * - max_elements (int): return at most this many elements;
	 * - max_field_chars (int): cut every field value to this many characters
	 *   and mark the field "truncated".
	 *
	 * @param WP_Post $post Post.
	 * @param array   $o    Options.
	 * @return array<int,array>
	 */
	abstract public function outline( $post, array $o ): array;

	/**
	 * Builder ids (keys of signatures.php) whose pages this adapter reads.
	 * A shared adapter — blocks, shortcodes — names several.
	 *
	 * @return string[]
	 */
	public function builders(): array {
		return array( $this->id() );
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function name(): string {
		$sig = AB_MCP_Builders::signature( $this->id() );
		return null !== $sig ? (string) $sig['name'] : $this->id();
	}

	/**
	 * Whether the post carries the builder's markers — whether or not the
	 * builder is active.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ): bool {
		return array() !== AB_MCP_Builders::markers_found( $this->id(), $post );
	}

	/**
	 * Whether the builder is loaded on this site (plugin or theme), by the
	 * checks its signature documents.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return true === AB_MCP_Builders::signature_active( $this->id() );
	}

	/**
	 * Plugin or theme version of the builder, when it is active and says.
	 *
	 * @return string|null
	 */
	public function version(): ?string {
		return AB_MCP_Builders::signature_version( $this->id() );
	}

	/**
	 * Version of the stored data format (e.g. _elementor_version), never the
	 * plugin version: a weekly plugin release must not switch a reader off.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public function data_version( $post ): ?string {
		return AB_MCP_Builders::signature_data_version( $this->id(), $post );
	}

	/**
	 * Storage kind of this page: 'A' (post_content is the only source),
	 * 'A2' (post_content is shown, the editor keeps a second copy), 'B' (the
	 * builder's own data is the source, post_content at most a copy) or 'C'
	 * (own database tables).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function storage( $post ): string {
		$sig = AB_MCP_Builders::signature( $this->id() );
		return null !== $sig ? (string) $sig['storage'] : 'A';
	}

	/**
	 * Every stored copy of the page that exists on this post:
	 * [ 'loc' => 'post_content'|'post_content_filtered'|'meta:<key>', 'shown' => bool, 'role' => 'source'|'copy'|'draft' ].
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array{loc:string,shown:bool,role:string}>
	 */
	public function copies( $post ): array {
		$sig = AB_MCP_Builders::signature( $this->id() );
		return AB_MCP_Builders::existing_copies( $post, null !== $sig ? $sig['copies'] : array(), $this->locked_meta_keys() );
	}

	/**
	 * Raw stored value of every copy, keyed by loc, exactly as in the
	 * database (strings; null for a copy that is missing). Never a key from
	 * locked_meta_keys(). Feeds layout_hash and Pro's undo.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,string|null>
	 */
	public function raw( $post ): array {
		return AB_MCP_Builders::raw_values( $post, $this->copies( $post ), $this->locked_meta_keys() );
	}

	/**
	 * Meta keys that hold page code (scripts, custom CSS) and are never read
	 * into an answer. A trailing "*" matches a prefix.
	 *
	 * @return string[]
	 */
	public function locked_meta_keys(): array {
		$sig = AB_MCP_Builders::signature( $this->id() );
		return null !== $sig ? $sig['locked_meta'] : array();
	}

	/**
	 * Whether this reader was checked against a real installation (the
	 * measurement folder of the plan). False makes every answer say so.
	 *
	 * @return bool
	 */
	public function verified(): bool {
		return false;
	}

	/**
	 * Whether an unpublished draft differs from the published layout (Beaver
	 * keeps both); null where the builder has no draft of its own.
	 *
	 * @param WP_Post $post Post.
	 * @return bool|null
	 */
	public function draft_differs( $post ): ?bool {
		return null;
	}
}
