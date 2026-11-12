<?php
/**
 * set_unit_base() in a process of its own (Z8b branch 2b, SyncUnitPairsTest:
 * Z44 under exit/Fatal and the time limit of PHP, plan 8.3). A child runner
 * loads this file (require of its plan) and calls ab_it_unit_base_probe():
 * the base of a unit as a step of a run made it, through the seam of the
 * pairs the plan installs. Test code only.
 *
 * @package AlphaBridge_MCP
 */

require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs.php';

/**
 * set_unit_base() of an operation with origin push.
 *
 * @param string   $source  The source.
 * @param array    $op      The operation.
 * @param string[] $written The parts the step wrote and checked.
 * @return string written, or the code of the error.
 */
function ab_it_unit_base_probe( $source, array $op, array $written ) {
	$done = AB_MCP_Sync_Pairs::set_unit_base( (string) $source, $op, AB_MCP_Sync_Unit_Proof::pushed( $written ) );
	return is_wp_error( $done ) ? $done->get_error_code() : 'written';
}
