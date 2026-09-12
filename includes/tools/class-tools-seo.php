<?php
/**
 * SEO tools. Detects Yoast, Rank Math or All in One SEO and reads/writes the SEO
 * title, meta description and focus keyword. Full write support for Yoast and
 * Rank Math (post meta); AIOSEO is detected but stores data in a custom table, so
 * writes to it are reported as unsupported rather than done unreliably.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Seo
 */
class AB_MCP_Tools_Seo extends AB_MCP_Tools_Base {

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return string yoast | rankmath | aioseo | none
	 */
	protected static function provider() {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( function_exists( 'aioseo' ) || defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return 'none';
	}

	/**
	 * Post-meta keys for the writable providers.
	 *
	 * @param string $provider Provider.
	 * @return array|null title/description/focus meta keys.
	 */
	protected static function keys( $provider ) {
		if ( 'yoast' === $provider ) {
			return array(
				'title'       => '_yoast_wpseo_title',
				'description' => '_yoast_wpseo_metadesc',
				'focus'       => '_yoast_wpseo_focuskw',
			);
		}
		if ( 'rankmath' === $provider ) {
			return array(
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
				'focus'       => 'rank_math_focus_keyword',
			);
		}
		return null;
	}

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_seo_detect',
			array(
				'description' => 'Detect the active SEO plugin (Yoast, Rank Math, AIOSEO or none).',
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'detect' ),
			)
		);

		$r->register(
			'wp_seo_get',
			array(
				'description' => 'Get the SEO title, meta description and focus keyword for a post.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
				),
				'callback'    => array( __CLASS__, 'get_seo' ),
			)
		);

	}

	/**
	 * Detect.
	 *
	 * @return array
	 */
	public static function detect() {
		$p = self::provider();
		return array(
			'provider'  => $p,
			'writable'  => in_array( $p, array( 'yoast', 'rankmath' ), true ),
			'note'      => 'aioseo' === $p ? 'AIOSEO stores SEO data in a custom table; writing is not supported by this tool.' : '',
		);
	}

	/**
	 * Get SEO fields.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_seo( $a ) {
		$id = self::i( $a, 'post_id' );
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		// edit_post, not merely read_post: the SEO title/description/focus keyword
		// are internal editorial metadata, so only someone who may edit the post
		// should see them — an author should not read a colleague's SEO fields on
		// a post they can only view.
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot edit this specific post.', 'alphabridge-mcp' ) );
		}
		$provider = self::provider();
		$keys     = self::keys( $provider );
		$out      = array(
			'post_id'  => $id,
			'provider' => $provider,
		);
		if ( $keys ) {
			$out['title']         = get_post_meta( $id, $keys['title'], true );
			$out['description']   = get_post_meta( $id, $keys['description'], true );
			$out['focus_keyword'] = get_post_meta( $id, $keys['focus'], true );
		} else {
			$out['note'] = 'none' === $provider
				? __( 'No supported SEO plugin detected.', 'alphabridge-mcp' )
				: __( 'AIOSEO detected; reading its custom-table data is not supported here.', 'alphabridge-mcp' );
		}
		return $out;
	}

}
