<?php
/**
 * SEO tools. Detects Yoast, Rank Math or All in One SEO and reads/writes the SEO
 * title, meta description and focus keyword. Full write support for Yoast and
 * Rank Math (post meta); AIOSEO is detected but stores data in a custom table, so
 * writes to it are reported as unsupported rather than done unreliably.
 *
 * wp_seo_set came with AlphaBridge MCP Pro only until 4.8.0. Pro registers its
 * copy of the name over this one where it is active, as with wp_get_post.
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
				'description' => 'Detect the active SEO plugin: Yoast SEO, Rank Math or All in One SEO, checked in that order, the first found counting. Returns {provider: "yoast", "rankmath", "aioseo" or "none", writable, note}; writable is true for Yoast and Rank Math, which keep their fields as post meta, and false for AIOSEO, whose own table is not written (note says so). Needs edit_posts. Read-only; takes no arguments. The fields of one post come from wp_seo_get.',
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'detect' ),
			)
		);

		$r->register(
			'wp_seo_get',
			array(
				'description' => 'Get the SEO title, meta description and focus keyword of one post as Yoast SEO or Rank Math store them. Returns {post_id, provider, title, description, focus_keyword}; a field never set is empty. With AIOSEO or no supported SEO plugin the fields are absent and note says why. Needs the right to edit the post; an unknown post_id returns a not-found error. Read-only; wp_seo_detect reports the active plugin alone.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array( 'post_id' => array( 'type' => 'integer', 'description' => 'Id of the post or page whose SEO fields are read.' ) ),
				),
				'callback'    => array( __CLASS__, 'get_seo' ),
			)
		);

		$r->register(
			'wp_seo_set',
			array(
				'description' => 'Set the SEO title, meta description and/or focus keyword of a post in the active SEO plugin\'s own fields: Yoast SEO, otherwise Rank Math (Yoast wins when both are active). Only the fields given are written, each overwriting the stored value; tags and line breaks are stripped. Returns updated, provider, changed (the fields written), saved_fields (each field as stored afterwards, read again; where it differs from what was sent, saved_differs and warning say so) and undo: the token for wp_undo, which puts the fields back as they were, or null with undo_note saying why. With neither active the call is refused, also where All in One SEO is the SEO plugin; an unknown post is a not-found error. wp_seo_get reads the values. Needs edit_posts and the right to edit the post and each SEO field.',
				'open_world'  => true, // SEO title and description show in search results.
				'capability'  => 'edit_posts',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id'       => array( 'type' => 'integer', 'description' => 'Id of the post, page or other entry.' ),
						'title'         => array( 'type' => 'string', 'description' => 'SEO title (the title tag the SEO plugin outputs), not the post title. Omitted: unchanged; an empty string stores an empty value.' ),
						'description'   => array( 'type' => 'string', 'description' => 'Meta description. Omitted: unchanged; an empty string stores an empty value.' ),
						'focus_keyword' => array( 'type' => 'string', 'description' => 'Focus keyword (keyphrase) the SEO plugin analyses the post for. Omitted: unchanged.' ),
					),
				),
				'callback'    => array( __CLASS__, 'set_seo' ),
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
			'note'      => 'aioseo' === $p ? __( 'AIOSEO stores SEO data in a custom table; writing is not supported by this tool. Its fields are edited in AIOSEO\'s panel in the post editor.', 'alphabridge-mcp' ) : '',
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found: no post has this id. Look it up with wp_list_posts or wp_search.', 'alphabridge-mcp' ) );
		}
		// edit_post, not merely read_post: the SEO title/description/focus keyword
		// are internal editorial metadata, so only someone who may edit the post
		// should see them — an author should not read a colleague's SEO fields on
		// a post they can only view.
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this specific post. Connect with an account that may edit it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
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
				? __( 'No supported SEO plugin detected: this tool reads the fields of Yoast SEO and Rank Math. With neither active there are no SEO fields to read; the post\'s title and excerpt are in wp_get_post.', 'alphabridge-mcp' )
				: __( 'AIOSEO detected; it keeps its fields in a table of its own, which this tool does not read. They are shown and edited in AIOSEO\'s panel in the post editor.', 'alphabridge-mcp' );
		}
		return $out;
	}

	/**
	 * An SEO field cleaned as sanitize_text_field() cleans it (tags and line
	 * breaks out, a lone < kept as &lt;), with every percent sign kept.
	 * sanitize_text_field() also removes a % followed by two hex digits, which
	 * breaks the variables Yoast SEO and Rank Math fill in a title or
	 * description: %%category%% would become %tegory%%. So the percent signs
	 * wait in a character of the private use area, taken out of the value
	 * first, and come back afterwards. The SEO plugin's own sanitising runs
	 * on top (update_post_meta()).
	 *
	 * @param string $value Value as given.
	 * @return string
	 */
	protected static function seo_text( $value ) {
		$kept = "\u{E000}";
		return str_replace( $kept, '%', sanitize_text_field( str_replace( array( $kept, '%' ), array( '', $kept ), (string) $value ) ) );
	}

	/**
	 * Set SEO fields.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function set_seo( $a ) {
		$id = self::i( $a, 'post_id' );
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found: no post has this id. Look it up with wp_list_posts or wp_search.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this specific post. Connect with an account that may edit it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		$provider = self::provider();
		$keys     = self::keys( $provider );
		if ( ! $keys ) {
			return new WP_Error(
				'ab_mcp_seo_unsupported',
				'aioseo' === $provider
					? __( 'AIOSEO stores SEO data in a custom table; writing is not supported by this tool. Its fields are edited in AIOSEO\'s panel in the post editor.', 'alphabridge-mcp' )
					: __( 'No supported SEO plugin detected: this tool writes the fields of Yoast SEO and Rank Math. With neither active there are no SEO fields to write; an administrator can install one of them under Plugins. The post\'s own title and excerpt are changed with wp_update_post.', 'alphabridge-mcp' )
			);
		}

		// Per-key capability. edit_post above is not enough: update_post_meta()
		// does not consult map_meta_cap, so a register_meta() auth_callback on an
		// SEO key would never be asked. Yoast's keys are "_"-prefixed (protected
		// meta), which makes the explicit check the only thing standing between a
		// caller and a key an auth_callback means to refuse.
		$fields = array(
			'title'         => $keys['title'],
			'description'   => $keys['description'],
			'focus_keyword' => $keys['focus'],
		);
		foreach ( $fields as $arg => $meta_key ) {
			if ( array_key_exists( $arg, $a ) && ! current_user_can( 'edit_post_meta', $id, $meta_key ) ) {
				return AB_MCP_Guidance::role_refusal(
					sprintf(
						/* translators: %s: meta key of the SEO field. */
						__( 'Your account may not edit the SEO field %s of this post: edit_post_meta says no, usually because the SEO plugin restricts it. Connect with an account that may edit it, such as an administrator.', 'alphabridge-mcp' ),
						$meta_key
					),
					array( 'code' => 'ab_mcp_forbidden_meta' )
				);
			}
		}

		$changed = array();
		$texts   = array();
		$names   = array();
		foreach ( $fields as $arg => $meta_key ) {
			if ( ! array_key_exists( $arg, $a ) ) {
				continue;
			}
			$texts[ $meta_key ] = self::seo_text( self::s( $a, $arg ) );
			$names[ $meta_key ] = $arg;
			$changed[]          = $arg;
		}
		$res = array(
			'updated'  => true,
			'provider' => $provider,
			'changed'  => $changed,
		);
		if ( ! class_exists( 'AB_MCP_Undo_Meta' ) ) {
			require_once dirname( __DIR__ ) . '/undo/class-undo-meta.php';
		}
		if ( array() === $texts ) {
			return AB_MCP_Undo_Answer::with( $res, AB_MCP_Undo_Answer::nothing_changed( 'wp_seo_get' ) );
		}

		// The rows of the fields before, the write, the read-back and the undo
		// point (since 4.14.0), as the meta tools have them.
		$want = array();
		foreach ( $texts as $meta_key => $text ) {
			$want[ $meta_key ] = AB_MCP_Undo_Answer::stored_form( $text );
		}
		$done = AB_MCP_Undo_Meta::write(
			array(
				'tool'    => 'wp_seo_set',
				'type'    => 'post',
				'id'      => $id,
				'keys'    => $want,
				'names'   => $names,
				'reader'  => 'wp_seo_get',
				'summary' => sprintf( 'post #%d: SEO %s', $id, implode( ', ', $changed ) ),
			),
			static function () use ( $id, $texts ) {
				foreach ( $texts as $meta_key => $text ) {
					// update_metadata() unslashes the value, so a backslash in a
					// title would be lost without wp_slash().
					update_post_meta( $id, $meta_key, wp_slash( $text ) );
				}
			}
		);
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return array_merge( $res, $done );
	}
}
