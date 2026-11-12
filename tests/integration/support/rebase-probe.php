<?php
/**
 * rebase() of a part in a process of its own (Z8b branch 2, SyncManifestTest:
 * Z38 under exit/Fatal and the time limit of PHP, plan 8.3). A child runner
 * loads this file (require of its plan) and calls ab_it_rebase_probe(): it
 * registers a kind of the code as the branches 5 and 6 bring them and moves
 * the bases of the part files of a pair on, through the seam of the pairs
 * the plan installs. Test code only.
 *
 * @package AlphaBridge_MCP
 */

require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs.php';

/**
 * Register the kind code alone and rebase() the part files of a pair.
 *
 * @param int    $id       The pair.
 * @param array  $values   The new bases (canon, base_live, base_staging, base_origin, last_run).
 * @param string $expected The snapshot hash the step read.
 * @return string written, or the code of the error.
 */
function ab_it_rebase_probe( $id, array $values, $expected ) {
	AB_MCP_Sync_Kinds::reset();
	$kind = new class() implements AB_MCP_Sync_Kind {
		public function kind() {
			return 'code';
		}
		public function phase() {
			return 2;
		}
		public function responsibility() {
			return array( 'part:plugin:files', 'part:theme:files' );
		}
		public function inventory( $source, $cursor ) {
			return array();
		}
		public function canonical( $object ) {
			return array();
		}
		public function export( array $refs ) {
			return array();
		}
		public function rights( $op, $ref ) {
			return true;
		}
		public function core_effects( $op ) {
			return array();
		}
		public function fields() {
			return array();
		}
		public function parts( array $payload ) {
			return array();
		}
		public function backup( array $part ) {
			return array();
		}
		public function write( array $part ) {
			return true;
		}
		public function read_back( array $part ) {
			return '';
		}
		public function restore_part( array $part ) {
			return true;
		}
		public function post_write( array $parts ) {
			return true;
		}
		public function verify( array $pair ) {
			return true;
		}
	};
	if ( true !== AB_MCP_Sync_Kinds::register( $kind ) ) {
		return 'not_registered';
	}
	$done = AB_MCP_Sync_Pairs::rebase( (int) $id, $values, (string) $expected, 'files', $kind );
	return is_wp_error( $done ) ? $done->get_error_code() : 'written';
}
