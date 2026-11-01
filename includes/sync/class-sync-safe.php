<?php
/**
 * The one gate in front of every function of WordPress the takeover's code
 * calls that reads stored values and unpacks them with maybe_unserialize()
 * (Z8, decision 11): unserialize() with every class makes an object of a
 * stored record and runs its __wakeup() or __unserialize(), before any check
 * of ours could see it.
 *
 * THE RULE for includes/sync/: no such function is called unless this gate
 * has read the rows it reads raw first and found no object, reference or
 * record in them (AB_MCP_Sync_Canon::holds_record()). Where the gate finds
 * one, or cannot read a row, the call does not happen and the caller locks
 * (*:php_object, *:unreadable_value). READS names, per call, the option rows
 * the function reads; a call that reads the meta of a post gets its rows
 * checked as well. tests/SyncSafeCallsTest.php holds the rule against the
 * code: every such call under includes/sync/ stands here, or on its list of
 * exceptions with the reason; the integration suite measures it with a
 * class that counts its __wakeup().
 *
 * Values the takeover reads itself come from the rows (AB_MCP_Sync_Rows) and
 * are unpacked without classes (AB_MCP_Sync_Canon::unserialized()).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Safe
 */
final class AB_MCP_Sync_Safe {

	/**
	 * The option rows each gated call reads with get_option() (or
	 * get_site_option(), the same row on a single site).
	 * - detect_all: AB_MCP_Builders::check_active() asks active_plugins and
	 *   active_sitewide_plugins (the plugin a signature names), template and
	 *   stylesheet through get_template() and get_stylesheet(); the meta of
	 *   the post it reads through get_post_meta();
	 * - upload_dir: _wp_upload_dir() asks siteurl, upload_path,
	 *   upload_url_path, uploads_use_yearmonth_folders and on a network
	 *   ms_files_rewriting (wp-includes/functions.php).
	 */
	const READS = array(
		'detect_all' => array( 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet' ),
		'upload_dir' => array( 'siteurl', 'upload_path', 'upload_url_path', 'uploads_use_yearmonth_folders', 'ms_files_rewriting' ),
	);

	/**
	 * Whether option rows hold no object: '' when they do not, else the name
	 * of the first that does or could not be read. A row that is not there
	 * holds nothing.
	 *
	 * @param string[] $names Option names.
	 * @return string
	 */
	public static function options_refusal( array $names ) {
		foreach ( $names as $name ) {
			$row = AB_MCP_Sync_Rows::option( (string) $name );
			if ( null === $row || ( is_array( $row ) && AB_MCP_Sync_Canon::holds_record( $row['value'] ) ) ) {
				return (string) $name;
			}
		}
		return '';
	}

	/**
	 * Whether stored values hold no object.
	 *
	 * @param array $rows Rows with 'value' (meta rows of AB_MCP_Sync_Rows::meta()).
	 * @return bool
	 */
	public static function rows_clean( array $rows ) {
		foreach ( $rows as $row ) {
			if ( AB_MCP_Sync_Canon::holds_record( isset( $row['value'] ) ? $row['value'] : '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * AB_MCP_Builders::detect_all() of a post, behind the gate: its meta rows
	 * and the options of READS['detect_all'] checked raw first.
	 *
	 * @param array $row  The post's row (AB_MCP_Sync_Rows::post()).
	 * @param array $meta Its meta rows (AB_MCP_Sync_Rows::meta()).
	 * @return array|null Its answer; null when the gate refused (a row holds
	 *                    an object or could not be read).
	 */
	public static function builders( array $row, array $meta ) {
		if ( ! self::rows_clean( $meta ) || '' !== self::options_refusal( self::READS['detect_all'] ) ) {
			return null;
		}
		$data     = (object) $row;
		$data->ID = (int) $row['ID'];
		return AB_MCP_Builders::detect_all( class_exists( 'WP_Post' ) ? new WP_Post( $data ) : $data );
	}

	/**
	 * The uploads folder WordPress works in (wp_upload_dir()'s basedir, its
	 * filter upload_dir included, as wp_delete_attachment_files() uses it),
	 * behind the gate: the options of READS['upload_dir'] checked raw first,
	 * the value worked out anew (not from the cache of an earlier call).
	 *
	 * @return string|null Null when the gate refused or no folder is given.
	 */
	public static function upload_basedir() {
		if ( '' !== self::options_refusal( self::READS['upload_dir'] ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false, true );
		return isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) && '' !== $uploads['basedir'] ? $uploads['basedir'] : null;
	}
}
