<?php
/**
 * Base class of a page-builder adapter: one builder's (or one family's) way of
 * storing a page, read as a compact outline.
 *
 * The contract between the free plugin (reading) and Pro (writing) is
 * AB_MCP_BUILDER_API (see class-builders.php). An adapter only needs code
 * where a builder needs code; block libraries and shortcode builders are
 * profiles (plain arrays): one shared adapter reads every block library, and
 * one class reads the shortcode builders, an instance per builder.
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
	 * Stable key, e.g. 'elementor', 'blocks', 'beaver', 'wpbakery'.
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
	 * A shared adapter — blocks — names several.
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
	 * Per post: a storage-B builder that shows post_content for this post
	 * (own_data_shown() false) makes it an 'A' page — what a change to
	 * post_content does there is what it does on any page.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function storage( $post ): string {
		$sig  = AB_MCP_Builders::signature( $this->id() );
		$kind = null !== $sig ? (string) $sig['storage'] : 'A';
		return 'B' === $kind && false === $this->own_data_shown( $post ) ? 'A' : $kind;
	}

	/**
	 * For a builder that keeps its pages in data of its own (storage B):
	 * whether the site shows that data for this post. true — it does, so
	 * post_content is only a copy; false — the builder falls back to
	 * post_content here (for example, it holds no layout for this post);
	 * null — not known, or not a storage-B builder.
	 *
	 * The default trusts the signature: a storage-B builder shows its own
	 * data. An adapter overrides this where the builder's own code decides
	 * per post. It must not call storage() (which calls this method) unless
	 * it overrides storage() as well.
	 *
	 * @param WP_Post $post Post.
	 * @return bool|null
	 */
	public function own_data_shown( $post ): ?bool {
		$sig  = AB_MCP_Builders::signature( $this->id() );
		$kind = null !== $sig ? (string) $sig['storage'] : $this->storage( $post );
		return 'B' === $kind ? true : null;
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
	 * The outline of a page whose stored layout exists but cannot be read:
	 * one locked element that names where the data is, so a broken page
	 * never looks like an empty one. Without include_locked: nothing.
	 *
	 * @param string $id   Element id.
	 * @param string $loc  Where the data is (meta key or post field); the element's type.
	 * @param string $note What the builder makes of it, where its code says.
	 * @param array  $o    Options (include_locked).
	 * @return array<int,array>
	 */
	protected static function unreadable_outline( $id, $loc, $note, array $o ): array {
		if ( array_key_exists( 'include_locked', $o ) && ! $o['include_locked'] ) {
			return array();
		}
		return array(
			array(
				'id'     => (string) $id,
				'type'   => (string) $loc,
				'parent' => null,
				'depth'  => 0,
				'locked' => true,
				'reason' => 'unreadable data',
				'note'   => (string) $note,
			),
		);
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
