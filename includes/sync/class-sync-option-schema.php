<?php
/**
 * The options the takeover may take over: the options of the core with a
 * schema (Z8, decision 27 of the plan of 01.11.2026). The names are those
 * populate_options() of WordPress 7.1.3 creates (wp-admin/includes/
 * schema.php:362, defaults from :411), each with its type and rule; not in
 * the list are the names AB_MCP_Sync_Exclusions leaves out (group B), those
 * another kind owns (AB_MCP_Sync_Kinds::OWNERSHIP: widgets, registration,
 * plugins, theme) and those the option guard refuses (AB_MCP_Option_Guard:
 * addresses, mail). The integration suite holds CORE against populate_options()
 * of the core: every name it creates is in CORE or left out for one of
 * these reasons, and CORE names nothing else.
 *
 * TYPES (the rule follows sanitize_option(), wp-includes/formatting.php:4923):
 * - int: a whole number of digits (absint);
 * - flag: '', '0' or '1';
 * - choice: one of the values listed, exactly the values the core writes
 *   (populate_options(), the settings screens and their sanitize_option(),
 *   the upgrade; the integration suite holds each against its place in the
 *   core);
 * - text: any text; addresses neutral;
 * - number: a number as text (gmt_offset);
 * - rewrite: a text of the permalink settings; writing it needs the rewrite
 *   rules built anew (A19, decision 33);
 * - ref: the id of one object (space post or term), 0 for none;
 * - refs: a serialized list of post ids.
 *
 * classify() answers for one stored option row, in this order: group B,
 * another owner, the option guard on the resolved name, the schema, and the
 * rest «Option eines Plugins, nicht eingeordnet (Z8d)» (LOCKS
 * option:unclassified).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-option-guard.php';
require_once __DIR__ . '/class-sync-kinds.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Option_Schema
 */
final class AB_MCP_Sync_Option_Schema {

	/** The core options with a schema: name => type (and space, values). */
	const CORE = array(
		'blogname'                     => array( 'type' => 'text' ),
		'blogdescription'              => array( 'type' => 'text' ),
		'start_of_week'                => array( 'type' => 'int' ),
		'use_balanceTags'              => array( 'type' => 'flag' ),
		'use_smilies'                  => array( 'type' => 'flag' ),
		'require_name_email'           => array( 'type' => 'flag' ),
		'comments_notify'              => array( 'type' => 'flag' ),
		'posts_per_rss'                => array( 'type' => 'int' ),
		'rss_use_excerpt'              => array( 'type' => 'flag' ),
		'default_category'             => array(
			'type'  => 'ref',
			'space' => 'term',
		),
		'default_comment_status'       => array(
			'type'   => 'choice',
			'values' => array( 'open', 'closed' ),
		),
		'default_ping_status'          => array(
			'type'   => 'choice',
			'values' => array( 'open', 'closed' ),
		),
		'default_pingback_flag'        => array( 'type' => 'flag' ),
		'posts_per_page'               => array( 'type' => 'int' ),
		'date_format'                  => array( 'type' => 'text' ),
		'time_format'                  => array( 'type' => 'text' ),
		'links_updated_date_format'    => array( 'type' => 'text' ),
		'comment_moderation'           => array( 'type' => 'flag' ),
		'moderation_notify'            => array( 'type' => 'flag' ),
		'permalink_structure'          => array( 'type' => 'rewrite' ),
		'hack_file'                    => array( 'type' => 'flag' ),
		'blog_charset'                 => array( 'type' => 'text' ),
		'moderation_keys'              => array( 'type' => 'text' ),
		'category_base'                => array( 'type' => 'rewrite' ),
		'ping_sites'                   => array( 'type' => 'text' ),
		'comment_max_links'            => array( 'type' => 'int' ),
		'gmt_offset'                   => array( 'type' => 'number' ),
		'default_email_category'       => array(
			'type'  => 'ref',
			'space' => 'term',
		),
		'comment_registration'         => array( 'type' => 'flag' ),
		'html_type'                    => array( 'type' => 'text' ),
		'use_trackback'                => array( 'type' => 'flag' ),
		'uploads_use_yearmonth_folders' => array( 'type' => 'flag' ),
		'default_link_category'        => array(
			'type'  => 'ref',
			'space' => 'term',
		),
		'show_on_front'                => array(
			'type'   => 'choice',
			'values' => array( 'posts', 'page' ),
		),
		'tag_base'                     => array( 'type' => 'rewrite' ),
		'show_avatars'                 => array( 'type' => 'flag' ),
		'avatar_rating'                => array(
			'type'   => 'choice',
			'values' => array( 'G', 'PG', 'R', 'X' ),
		),
		'thumbnail_size_w'             => array( 'type' => 'int' ),
		'thumbnail_size_h'             => array( 'type' => 'int' ),
		'thumbnail_crop'               => array( 'type' => 'flag' ),
		'medium_size_w'                => array( 'type' => 'int' ),
		'medium_size_h'                => array( 'type' => 'int' ),
		'avatar_default'               => array( 'type' => 'text' ),
		'large_size_w'                 => array( 'type' => 'int' ),
		'large_size_h'                 => array( 'type' => 'int' ),
		'image_default_link_type'      => array( 'type' => 'text' ),
		'image_default_size'           => array( 'type' => 'text' ),
		'image_default_align'          => array( 'type' => 'text' ),
		'close_comments_for_old_posts' => array( 'type' => 'flag' ),
		'close_comments_days_old'      => array( 'type' => 'int' ),
		'thread_comments'              => array( 'type' => 'flag' ),
		'thread_comments_depth'        => array( 'type' => 'int' ),
		'page_comments'                => array( 'type' => 'flag' ),
		'comments_per_page'            => array( 'type' => 'int' ),
		'default_comments_page'        => array(
			'type'   => 'choice',
			'values' => array( 'newest', 'oldest' ),
		),
		'comment_order'                => array(
			'type'   => 'choice',
			'values' => array( 'asc', 'desc' ),
		),
		'sticky_posts'                 => array(
			'type'  => 'refs',
			'space' => 'post',
		),
		'timezone_string'              => array( 'type' => 'text' ),
		'page_for_posts'               => array(
			'type'  => 'ref',
			'space' => 'post',
		),
		'page_on_front'                => array(
			'type'  => 'ref',
			'space' => 'post',
		),
		'default_post_format'          => array( 'type' => 'text' ),
		'link_manager_enabled'         => array( 'type' => 'flag' ),
		'site_icon'                    => array(
			'type'  => 'ref',
			'space' => 'post',
		),
		'medium_large_size_w'          => array( 'type' => 'int' ),
		'medium_large_size_h'          => array( 'type' => 'int' ),
		'wp_page_for_privacy_policy'   => array(
			'type'  => 'ref',
			'space' => 'post',
		),
		'show_comments_cookies_opt_in' => array( 'type' => 'flag' ),
		'disallowed_keys'              => array( 'type' => 'text' ),
		'comment_previously_approved'  => array( 'type' => 'flag' ),
		'auto_update_core_dev'         => array(
			'type'   => 'choice',
			'values' => array( 'enabled' ),
		),
		'auto_update_core_minor'       => array(
			'type'   => 'choice',
			'values' => array( 'enabled' ),
		),
		'auto_update_core_major'       => array(
			'type'   => 'choice',
			'values' => array( 'enabled', 'disabled', 'unset' ),
		),
		'wp_attachment_pages_enabled'  => array( 'type' => 'flag' ),
		'wp_notes_notify'              => array( 'type' => 'flag' ),
	);

	/**
	 * What the takeover does with one stored option row.
	 *
	 * @param string      $name The name exactly as the row has it.
	 * @param string|null $raw  The value as the row holds it; read when null.
	 * @return array status: core (with schema), excluded (group B), owner
	 *               (another kind, or locked there), guarded (the option
	 *               guard refuses it; reason: its way), unreachable (WordPress
	 *               reads another row under this name), unclassified (locked,
	 *               Z8d), unreadable (the guard could not look it up); key,
	 *               stage, reason.
	 */
	public static function classify( $name, $raw = null ) {
		$name  = (string) $name;
		$owner = AB_MCP_Sync_Kinds::option( $name );
		if ( 'excluded' === $owner['status'] ) {
			return self::answer( 'excluded', $owner['key'], null, $owner['reason'] );
		}
		if ( 'option' !== $owner['kind'] || 'z8' !== $owner['status'] ) {
			return self::answer( 'owner', $owner['key'], $owner['stage'], $owner['reason'] ) + array( 'owner' => $owner );
		}
		if ( null === $raw ) {
			$row = AB_MCP_Sync_Rows::option( $name );
			if ( null === $row ) {
				return self::answer( 'unreadable', 'option:' . $name, null, 'The option could not be read. Try again; if it keeps failing, the database error log names the cause.' );
			}
			$raw = false === $row ? '' : $row['value'];
		}
		// resolve_option() reads the value of the row it settles on with
		// get_option(), whose maybe_unserialize() makes an object of any
		// class. A row that holds one is settled here by its name alone: a
		// stored name WordPress acts on as it is settles on its own row, and
		// the guard asks the name the same questions there.
		if ( AB_MCP_Sync_Canon::holds_record( $raw ) ) {
			$real = AB_MCP_Sync_Option_Name::real( $name );
			if ( $real !== $name ) {
				$resolved = array( 'name' => $real );
			} else {
				$refused  = AB_MCP_Option_Guard::option_write_refused( $name );
				$resolved = is_wp_error( $refused ) ? $refused : array( 'name' => $name );
			}
		} else {
			$resolved = AB_MCP_Option_Guard::resolve_option( $name );
		}
		if ( is_wp_error( $resolved ) ) {
			if ( 'ab_mcp_option_unreadable' === $resolved->get_error_code() ) {
				return self::answer( 'unreadable', 'option:' . $name, null, $resolved->get_error_message() );
			}
			return self::answer( 'guarded', 'option:' . $name, null, $resolved->get_error_message() );
		}
		if ( $resolved['name'] !== $name ) {
			return self::answer( 'unreachable', 'option:' . $name, null, sprintf( 'WordPress reads the row %s under this name, so this row is never read and is not taken over.', $resolved['name'] ) );
		}
		if ( isset( self::CORE[ $name ] ) ) {
			return self::answer( 'core', 'option:' . $name, null, null ) + array( 'schema' => self::CORE[ $name ] );
		}
		$lock = AB_MCP_Sync_Kinds::lock( 'option', 'unclassified' );
		return self::answer( 'unclassified', $lock['key'], $lock['stage'], $lock['reason'] );
	}

	/**
	 * Whether a stored value keeps the rule of its type.
	 *
	 * @param array  $schema Entry of CORE.
	 * @param string $raw    As stored.
	 * @return bool
	 */
	public static function fits( array $schema, $raw ) {
		$raw = (string) $raw;
		switch ( $schema['type'] ) {
			case 'int':
			case 'ref':
				return 1 === preg_match( '/^\d+\z/', $raw );
			case 'flag':
				return in_array( $raw, array( '', '0', '1' ), true );
			case 'choice':
				return in_array( $raw, $schema['values'], true );
			case 'number':
				return is_numeric( $raw );
			case 'refs':
				$read = AB_MCP_Sync_Canon::unserialized( $raw );
				if ( ! $read['ok'] || ! is_array( $read['value'] ) || ! array_is_list( $read['value'] ) ) {
					return false;
				}
				foreach ( $read['value'] as $id ) {
					if ( ! ( is_int( $id ) || ( is_string( $id ) && preg_match( '/^\d+\z/', $id ) ) ) ) {
						return false;
					}
				}
				return true;
		}
		return true;
	}

	/**
	 * The canonical value of a core option by its type: references as
	 * markers, texts with the addresses neutral. A value that breaks its
	 * rule locks the option (option:off_schema) and is kept as stored.
	 *
	 * @param array  $schema Entry of CORE.
	 * @param string $raw    As stored.
	 * @param array  $ctx    Context.
	 * @param array  $acc    Accumulator.
	 * @return mixed
	 */
	public static function canonical( array $schema, $raw, array $ctx, array &$acc ) {
		$raw = (string) $raw;
		if ( ! self::fits( $schema, $raw ) ) {
			AB_MCP_Sync_Canon::lock( $acc, 'option', 'off_schema', 'option_value' );
			return AB_MCP_Sync_Canon::value( $raw, $ctx, $acc, 'option', 'option_value' );
		}
		if ( 'ref' === $schema['type'] ) {
			if ( (int) $raw <= 0 ) {
				return $raw;
			}
			$acc['refs'][] = array(
				'space'    => $schema['space'],
				'id'       => (int) $raw,
				'at'       => 'option_value',
				'strength' => 'strong',
			);
			return AB_MCP_Sync_Canon::ref( $schema['space'], (int) $raw );
		}
		if ( 'refs' === $schema['type'] ) {
			$read = AB_MCP_Sync_Canon::unserialized( $raw );
			$out  = array();
			foreach ( $read['value'] as $i => $id ) {
				$acc['refs'][] = array(
					'space'    => $schema['space'],
					'id'       => (int) $id,
					'at'       => 'option_value.' . $i,
					'strength' => 'strong',
				);
				$out[]         = AB_MCP_Sync_Canon::ref( $schema['space'], (int) $id ) + ( is_string( $id ) ? array( 'text' => true ) : array() );
			}
			return array( '~php' => $out );
		}
		return AB_MCP_Sync_Canon::value( $raw, $ctx, $acc, 'option', 'option_value' );
	}

	/**
	 * @param string      $status Status.
	 * @param string      $key    Key of the table that answered.
	 * @param string|null $stage  Stage.
	 * @param string|null $reason Reason.
	 * @return array
	 */
	private static function answer( $status, $key, $stage, $reason ) {
		return array(
			'status' => $status,
			'key'    => $key,
			'stage'  => $stage,
			'reason' => $reason,
		);
	}
}

/**
 * Class AB_MCP_Sync_Option_Name: the name WordPress acts on for a name, as
 * the option guard settles it (AB_MCP_Option_Guard::real_option_name():
 * trimmed, the two legacy names the core redirects), without reading a row.
 */
final class AB_MCP_Sync_Option_Name extends AB_MCP_Option_Guard {

	/**
	 * @param string $name Name.
	 * @return string
	 */
	public static function real( $name ) {
		return self::real_option_name( $name );
	}
}
