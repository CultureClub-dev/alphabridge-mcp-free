<?php
/**
 * Adapter for pages made of blocks (storage A): the WordPress core blocks and
 * every block library listed in signatures.php with family 'blocks'. The
 * libraries differ only in their profiles (data in ../profiles/), so one
 * adapter reads them all — a page mixing core, Kadence and Spectra blocks is
 * one outline.
 *
 * It also reads what WordPress shows when no builder is active, which is why
 * AB_MCP_Builders::for_post() falls back to it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Adapter_Blocks
 */
class AB_MCP_Builder_Adapter_Blocks extends AB_MCP_Builder_Adapter {

	/**
	 * Adapter id; also the builder id the tools report for a page of core
	 * blocks.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'blocks';
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'WordPress blocks';
	}

	/**
	 * 'blocks' and every block library of signatures.php.
	 *
	 * @return string[]
	 */
	public function builders(): array {
		$ids = array( 'blocks' );
		foreach ( AB_MCP_Builders::signatures() as $id => $sig ) {
			if ( 'blocks' === $sig['family'] ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * A post of blocks.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ): bool {
		return AB_MCP_Builders::has_blocks( $post );
	}

	/**
	 * Blocks are WordPress itself.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return true;
	}

	/**
	 * The WordPress version.
	 *
	 * @return string|null
	 */
	public function version(): ?string {
		return isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : null;
	}

	/**
	 * Blocks keep no page-level format version.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public function data_version( $post ): ?string {
		return null;
	}

	/**
	 * Always A: post_content is the page.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function storage( $post ): string {
		return 'A';
	}

	/**
	 * post_content, plus the extra copies a block library on this page keeps
	 * (Pagelayer's tree in pagelayer-data).
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array{loc:string,shown:bool,role:string}>
	 */
	public function copies( $post ): array {
		$copies = array(
			array(
				'loc'   => 'post_content',
				'shown' => true,
				'role'  => 'source',
			),
		);
		foreach ( $this->libraries_on( $post ) as $sig ) {
			foreach ( $sig['copies'] as $copy ) {
				if ( 'post_content' !== $copy['loc'] ) {
					$copies[] = $copy;
				}
			}
		}
		return AB_MCP_Builders::existing_copies( $post, $copies, $this->locked_meta_keys() );
	}

	/**
	 * Page-code keys of every block library (Pagelayer's header and footer
	 * code), so none of them is ever read.
	 *
	 * @return string[]
	 */
	public function locked_meta_keys(): array {
		$keys = array();
		foreach ( AB_MCP_Builders::signatures() as $sig ) {
			if ( 'blocks' === $sig['family'] ) {
				$keys = array_merge( $keys, $sig['locked_meta'] );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * The outline of post_content.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $o    Options.
	 * @return array<int,array>
	 */
	public function outline( $post, array $o ): array {
		return AB_MCP_Block_Reader::outline( (string) $post->post_content, $o );
	}

	/**
	 * The reader and the core profile were checked against real
	 * installations; elements read otherwise say so in their note.
	 *
	 * @return bool
	 */
	public function verified(): bool {
		return true;
	}

	/**
	 * Signatures of the block libraries whose markers this post carries.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array>
	 */
	private function libraries_on( $post ) {
		$out = array();
		foreach ( AB_MCP_Builders::signatures() as $id => $sig ) {
			if ( 'blocks' === $sig['family'] && array() !== AB_MCP_Builders::markers_found( $id, $post ) ) {
				$out[] = $sig;
			}
		}
		return $out;
	}
}
