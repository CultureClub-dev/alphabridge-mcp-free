<?php
/**
 * The backups of a run (Z8, decisions 17 and 20 of the plan of 01.11.2026;
 * branch 8): what a write boundary replaces, kept in the area backups of
 * the protected store before the first mutation, one group per run, bound
 * to the run (AB_MCP_Protected_Store::open() with its run_id), so the
 * clean-up keeps it while the run is not closed and afterwards by the
 * retention contract (AB_MCP_Sync_Schema::backup_runs()).
 *
 * A backup is one file of the group: the data in the one spelling of
 * AB_MCP_Sync_Canon::encode(), under FORMAT, named by the step and the
 * part, written once and read back against its SHA-256 before the step
 * goes on (decision 17: no mutation without a backup checked by its hash;
 * a backup that does not read back ends the step before the mutation).
 * The journal of the step keeps the reference (name, sha256, bytes).
 *
 * Nothing here reads a value of the site: the kinds hand the rows over.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Backup
 */
final class AB_MCP_Sync_Backup {

	/** The format of a backup file. */
	const FORMAT = 'ab-sync-backup/1';

	/** The label of the group of a run, followed by its run_id. */
	const LABEL = 'run-';

	/** Bytes one backup holds at most (one file of the store, AB_MCP_Protected_Store::FILE_MAX). */
	const MAX_BYTES = 16777216;

	/** @var array<int,string> The group of each run this request found or made. */
	private static $groups = array();

	/**
	 * The group of a run in the area backups: the one bound to the run, or a
	 * new one. The group is bound to the run (the clean-up keeps it while
	 * the run is not closed) and labelled with its id.
	 *
	 * @param int $run_id Run.
	 * @return string|WP_Error The id of the group.
	 */
	public static function group( $run_id ) {
		$run_id = (int) $run_id;
		if ( $run_id < 1 ) {
			return new WP_Error( 'ab_mcp_sync_backup_run', 'A backup belongs to a run. Pass its run_id.' );
		}
		if ( isset( self::$groups[ $run_id ] ) ) {
			return self::$groups[ $run_id ];
		}
		$groups = AB_MCP_Protected_Store::groups( 'backups' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		foreach ( $groups as $entry ) {
			if ( self::LABEL . $run_id === (string) $entry['label'] && (int) $entry['run_id'] === $run_id ) {
				self::$groups[ $run_id ] = (string) $entry['gid'];
				return self::$groups[ $run_id ];
			}
		}
		$gid = AB_MCP_Protected_Store::open(
			'backups',
			array(
				'run_id' => $run_id,
				'label'  => self::LABEL . $run_id,
			)
		);
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		self::$groups[ $run_id ] = (string) $gid;
		return self::$groups[ $run_id ];
	}

	/**
	 * Keep a backup: written once, read back against its SHA-256. Only a
	 * backup that read back is a backup (decision 17).
	 *
	 * @param int    $run_id Run.
	 * @param string $name   Name of the file: a-z, 0-9, ".", "_", "-", up to 64.
	 * @param array  $data   What is kept (the rows a part replaces, as the kind hands them over).
	 * @return array{name:string,sha256:string,bytes:int}|WP_Error The reference the journal keeps.
	 */
	public static function put( $run_id, $name, array $data ) {
		$gid = self::group( $run_id );
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		$text = AB_MCP_Sync_Canon::encode(
			array(
				'data'   => $data,
				'format' => self::FORMAT,
				'run_id' => (int) $run_id,
			)
		);
		if ( '' === $text ) {
			return new WP_Error( 'ab_mcp_sync_backup_encode', 'What the step replaces cannot be written as a backup (a value JSON cannot hold), so the step writes nothing. Use the editor in wp-admin to take this object over by hand.' );
		}
		if ( strlen( $text ) > self::MAX_BYTES ) {
			return new WP_Error( 'ab_mcp_sync_backup_too_large', sprintf( 'The backup of what the step replaces would hold %1$d bytes, and one backup holds at most %2$d, so the step writes nothing. Use the editor in wp-admin to take the object over by hand.', strlen( $text ), self::MAX_BYTES ) );
		}
		$put = AB_MCP_Protected_Store::put( 'backups', $gid, (string) $name, $text );
		if ( is_wp_error( $put ) ) {
			return $put;
		}
		$ref = array(
			'name'   => (string) $put['name'],
			'sha256' => (string) $put['sha256'],
			'bytes'  => (int) $put['bytes'],
		);
		// Read back against the hash before anything is written to the site.
		$back = AB_MCP_Protected_Store::get( 'backups', $gid, $ref['name'], $ref['sha256'] );
		if ( ! is_string( $back ) || ! hash_equals( hash( 'sha256', $text ), hash( 'sha256', $back ) ) ) {
			return new WP_Error( 'ab_mcp_sync_backup_readback', 'The backup of what the step replaces did not read back from the protected store as it was written, so the step writes nothing. Ask the host to check the disk of the protected store, then call again.' );
		}
		return $ref;
	}

	/**
	 * Read a backup against the reference the journal keeps.
	 *
	 * @param int   $run_id Run.
	 * @param array $ref    name, sha256.
	 * @return array|WP_Error The data kept.
	 */
	public static function get( $run_id, array $ref ) {
		$gid = self::group( $run_id );
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		if ( ! isset( $ref['name'], $ref['sha256'] ) ) {
			return new WP_Error( 'ab_mcp_sync_backup_ref', 'The journal names no backup for this part, so nothing of it is put back. Check the object in wp-admin.' );
		}
		$text = AB_MCP_Protected_Store::get( 'backups', $gid, (string) $ref['name'], (string) $ref['sha256'] );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = json_decode( $text, true, 64 );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) || (int) $run_id !== ( $data['run_id'] ?? null ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error( 'ab_mcp_sync_backup_shape', 'The backup is not one this run wrote, so nothing of it is put back. Check the object in wp-admin.' );
		}
		return $data['data'];
	}

	/**
	 * Forget the groups this request found (tests, a reset of the store).
	 */
	public static function forget() {
		self::$groups = array();
	}
}
