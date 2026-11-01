<?php
/**
 * Contract A of the plan of 01.11.2026 (Z8): what the engine of one kind
 * gives the takeover. One kind per store of WordPress (post, term,
 * attachment, menu, widget_area, custom_css, option), registered with
 * AB_MCP_Sync_Kinds::register(); Z8b, Z8c and Z8d add kinds, schemas,
 * releases and field rules the same way.
 *
 * The promise of every kind: nothing written without a backup and a
 * journal entry first, success only where the hash read back is the
 * target, every deviation named, nothing done on a way whose consequences
 * are not completely known.
 *
 * The implementations follow in branches 4 to 11 of the plan; the
 * signatures here are their common shape.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface AB_MCP_Sync_Kind
 */
interface AB_MCP_Sync_Kind {

	/**
	 * @return string The name of the kind, such as 'post' (a-z, 0-9, _).
	 */
	public function kind();

	/**
	 * @return int The phase of a run its writes belong to (AB_MCP_Sync_Kinds::PHASES).
	 */
	public function phase();

	/**
	 * @return string[] The keys of AB_MCP_Sync_Kinds::OWNERSHIP this kind
	 *                  is responsible for: all of those the table gives it,
	 *                  and no other.
	 */
	public function responsibility();

	/**
	 * The objects of this kind on one side, a part at a time: for each its
	 * identity, hash, refs, size and whether it is complete; for media the
	 * file list; the smallest unit the credential check looks at.
	 *
	 * @param mixed      $source The side (AB_MCP_Sync_Source).
	 * @param mixed|null $cursor Where the part before ended; null at the start.
	 * @return array|WP_Error 'objects' and 'cursor' (null when done).
	 */
	public function inventory( $source, $cursor );

	/**
	 * @param mixed $object One object as inventory() or export() read it.
	 * @return array|WP_Error Its canonical form (decision 11).
	 */
	public function canonical( $object );

	/**
	 * @param string[] $refs Objects of this site.
	 * @return array|WP_Error The objects as a package carries them.
	 */
	public function export( array $refs );

	/**
	 * @param string $op  Operation (create, update).
	 * @param string $ref Object, '' for a new one.
	 * @return true|WP_Error Whether the account may, meta keys and
	 *                       assignments included (decision 28).
	 */
	public function rights( $op, $ref );

	/**
	 * @param string $op Operation.
	 * @return array What WordPress changes besides the object (survey 1.7),
	 *               each with the reason it is allowed or locked.
	 */
	public function core_effects( $op );

	/**
	 * @return array The fields of rows this kind shares with others
	 *               (decision 24); empty when it shares none.
	 */
	public function fields();

	/**
	 * @param array $payload What one step writes.
	 * @return array|WP_Error Its partial mutations, for the journal.
	 */
	public function parts( array $payload );

	/**
	 * @param array $part One partial mutation.
	 * @return array|WP_Error The backup of what it replaces, with its SHA-256.
	 */
	public function backup( array $part );

	/**
	 * @param array $part One partial mutation.
	 * @return true|WP_Error Written through WordPress's own functions.
	 */
	public function write( array $part );

	/**
	 * @param array $part One partial mutation.
	 * @return string|WP_Error The SHA-256 of what is stored now.
	 */
	public function read_back( array $part );

	/**
	 * @param array $part One partial mutation with its journal entry.
	 * @return true|WP_Error Put back from its backup, only where it is
	 *                       still byte for byte what the step wrote.
	 */
	public function restore_part( array $part );

	/**
	 * @param array $parts The partial mutations of a step that are done.
	 * @return true|WP_Error Caches and what follows a write (decision 33).
	 */
	public function post_write( array $parts );

	/**
	 * @param array $pair A pair this kind wrote.
	 * @return true|WP_Error Whether the live object is its target.
	 */
	public function verify( array $pair );
}
