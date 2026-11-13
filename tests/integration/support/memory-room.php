<?php
/**
 * A function a plan of AB_MCP_IT_Child can call (CodeStageTest, Behebung 5,
 * GitHub-Codex on free#153, P2): the memory_limit of the child set to what it
 * uses now and some room more, so that a file larger than that room cannot be
 * read whole in it. Test code only.
 *
 * @package AlphaBridge_MCP
 */

/**
 * The memory_limit of this process: what it uses now and $room bytes more.
 *
 * @param int $room Bytes.
 * @return int|false The limit set, or false where PHP did not take it.
 */
function ab_it_memory_room( $room ) {
	$limit = memory_get_usage( true ) + (int) $room;
	return false === ini_set( 'memory_limit', (string) $limit ) ? false : $limit; // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- the test sets the room of its own child.
}
