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
 * The journal of the step keeps the reference (name, sha256, bytes). It is
 * read with AB_MCP_Sync_Canon::decode(), the inverse of that spelling:
 * bytes that are no UTF-8 come back as their bytes, not as the marker
 * {"~b"} (GitHub Codex round 2 of branch 8, P2).
 *
 * Nothing here reads a value of the site: the kinds hand the rows over.
 *
 * Messages are in gettext, in the language of the site, as the other
 * classes of the takeover; each names the way. Codes and machine values
 * (kinds, purposes, parameters, fields) stay as the tools name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/class-store-budget.php';
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

	/**
	 * The group of a run in the area backups: the one bound to the run, or a
	 * new one. The group is bound to the run (the clean-up keeps it while
	 * the run is not closed) and labelled with its id.
	 *
	 * The run is taken as it is given (Behebung 2 of Z8b branch 3): a whole
	 * number of 1 or more, or the same written in decimal digits without a
	 * leading zero or anything around them; anything else ("5.7", "5abc",
	 * " 5", "05", a number past PHP_INT_MAX) is refused, never cast to a run
	 * it does not name. The groups compared come from groups(), whose
	 * sidecars are whole by AB_MCP_Protected_Store::SIDECAR (read_entry()):
	 * a group whose sidecar is unknown is never used again.
	 *
	 * @param int|string $run_id Run.
	 * @return string|WP_Error The id of the group.
	 */
	public static function group( $run_id ) {
		$run_id = AB_MCP_Sync_Schema::whole_number( $run_id, 1 );
		if ( null === $run_id ) {
			return new WP_Error( 'ab_mcp_sync_backup_run', __( 'A backup belongs to a run. Pass its run_id.', 'alphabridge-mcp' ) );
		}
		// No memory of a group found before: each call asks groups() anew, so
		// a group whose sidecar turned unknown since is never used again
		// (Behebung 3 of Z8b branch 3; a cache returned it unchecked).
		$groups = AB_MCP_Protected_Store::groups( 'backups' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		foreach ( $groups as $entry ) {
			if ( self::LABEL . $run_id === $entry['label'] && $entry['run_id'] === $run_id ) {
				return $entry['gid'];
			}
		}
		$gid = AB_MCP_Protected_Store::open(
			'backups',
			array(
				'run_id' => $run_id,
				'label'  => self::LABEL . $run_id,
			)
		);
		return $gid;
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
			return new WP_Error( 'ab_mcp_sync_backup_encode', __( 'What the step replaces cannot be written as a backup (a value JSON cannot hold), so the step writes nothing. Use the editor in wp-admin to take this object over by hand.', 'alphabridge-mcp' ) );
		}
		if ( strlen( $text ) > self::MAX_BYTES ) {
			/* translators: 1: size of the backup in bytes, 2: the most bytes one backup holds. */
			return new WP_Error( 'ab_mcp_sync_backup_too_large', sprintf( __( 'The backup of what the step replaces would hold %1$d bytes, and one backup holds at most %2$d, so the step writes nothing. Use the editor in wp-admin to take the object over by hand.', 'alphabridge-mcp' ), strlen( $text ), self::MAX_BYTES ) );
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
			return new WP_Error( 'ab_mcp_sync_backup_readback', __( 'The backup of what the step replaces did not read back from the protected store as it was written, so the step writes nothing. Ask the host to check the disk of the protected store, then call again.', 'alphabridge-mcp' ) );
		}
		return $ref;
	}

	/**
	 * The bytes a backup of this data takes in the store, as put() writes
	 * it (uncompressed, which is what the store counts as used and what a
	 * run books): the upper bound the plan reserves with (GitHub Codex round
	 * 6 on free#137, P2: the plan reserved from the canonical size of the
	 * staging side only, while the backup holds the rows of this site).
	 *
	 * @param array $data What is kept (the rows a part replaces, or the absence of the object).
	 * @return int
	 */
	public static function size_of( array $data ) {
		return strlen(
			AB_MCP_Sync_Canon::encode(
				array(
					'data'   => $data,
					'format' => self::FORMAT,
					'run_id' => PHP_INT_MAX,
				)
			)
		);
	}

	/**
	 * Whether a backup of this data fits what the run booked, beside the
	 * backups the run holds already (the used bytes of its groups): asked
	 * right before the backup is written, before the mutation. A run whose
	 * booking the store does not know fails closed.
	 *
	 * @param int   $run_id Run.
	 * @param array $data   What is to be kept.
	 * @return true|WP_Error
	 */
	public static function fits( $run_id, array $data ) {
		// The run as group() takes it (Behebung 3 of Z8b branch 3).
		$run_id = AB_MCP_Sync_Schema::whole_number( $run_id, 1 );
		if ( null === $run_id ) {
			return new WP_Error( 'ab_mcp_sync_backup_run', __( 'A backup belongs to a run. Pass its run_id.', 'alphabridge-mcp' ) );
		}
		$bytes    = self::size_of( $data );
		$bookings = AB_MCP_Store_Budget::bookings();
		if ( is_wp_error( $bookings ) ) {
			return $bookings;
		}
		if ( ! isset( $bookings[ $run_id ] ) ) {
			/* translators: %d: run number. */
			return new WP_Error( 'ab_mcp_sync_backup_booking', sprintf( __( 'The run %d has no booking in the protected store, so no backup is written and nothing of the object. Plan the run again (its check before the first write books what the run needs).', 'alphabridge-mcp' ), $run_id ) );
		}
		$booked = $bookings[ $run_id ]['bytes'];
		$used   = 0;
		$groups = AB_MCP_Protected_Store::groups( 'backups' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		// Every group of the run counts: the fields of each are whole by
		// AB_MCP_Protected_Store::SIDECAR (groups()), and no memory of this
		// request picks one (Behebung 3: group() keeps none).
		foreach ( $groups as $entry ) {
			if ( $entry['run_id'] === $run_id ) {
				$used += $entry['used'];
			}
		}
		if ( $used + $bytes > $booked ) {
			/* translators: 1: size of the backup in bytes, 2: bytes the run booked, 3: bytes its backups hold already. */
			return new WP_Error( 'ab_mcp_sync_backup_budget', sprintf( __( 'The backup of this object would take %1$d bytes, and the run booked %2$d of which its backups hold %3$d already: it would pass the booking, so nothing is written for the object. Plan the run again (the plan reserves from the objects of this site as they are now), or choose fewer objects for one run.', 'alphabridge-mcp' ), $bytes, $booked, $used ), array( 'bytes' => $bytes, 'booked' => $booked, 'used' => $used ) );
		}
		return true;
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
			return new WP_Error( 'ab_mcp_sync_backup_ref', __( 'The journal names no backup for this part, so nothing of it is put back. Check the object in wp-admin.', 'alphabridge-mcp' ) );
		}
		$text = AB_MCP_Protected_Store::get( 'backups', $gid, (string) $ref['name'], (string) $ref['sha256'] );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		// Read back through the inverse of the one spelling: bytes that are
		// no UTF-8 ({"~b"}) are their bytes again, so the way back writes
		// what the row held, never the marker (GitHub Codex round 2, P2).
		$data = AB_MCP_Sync_Canon::decode( $text, 64 );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) || (int) $run_id !== ( $data['run_id'] ?? null ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error( 'ab_mcp_sync_backup_shape', __( 'The backup is not one this run wrote (or a value in it does not read back as it was written), so nothing of it is put back. Check the object in wp-admin.', 'alphabridge-mcp' ) );
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
