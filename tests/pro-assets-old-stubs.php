<?php
/**
 * Stand-ins for the four stylesheet loaders of a Pro add-on before 4.16.0
 * (includes/undo-card.php, deploy-card.php, abilities/abilities-card.php and
 * affiliate-box.php of Pro 4.15.0): each notes the hook it was called with
 * and whether the core's stylesheet, which its own builds on, was enqueued
 * by then.
 *
 * Declares functions of Pro's, so it is loaded only inside a test that runs
 * in a process of its own; the rest of the suite never sees them.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

$GLOBALS['ab_test_old_pro_assets'] = array();

function ab_mcp_pro_undo_assets( $hook ) {
	$GLOBALS['ab_test_old_pro_assets'][] = array( __FUNCTION__, $hook, isset( $GLOBALS['ab_test_styles']['ab-mcp-admin'] ) );
}

function ab_mcp_pro_deploy_assets( $hook ) {
	$GLOBALS['ab_test_old_pro_assets'][] = array( __FUNCTION__, $hook, isset( $GLOBALS['ab_test_styles']['ab-mcp-admin'] ) );
}

function ab_mcp_pro_abilities_assets( $hook ) {
	$GLOBALS['ab_test_old_pro_assets'][] = array( __FUNCTION__, $hook, isset( $GLOBALS['ab_test_styles']['ab-mcp-admin'] ) );
}

function ab_mcp_pro_aff_assets( $hook ) {
	$GLOBALS['ab_test_old_pro_assets'][] = array( __FUNCTION__, $hook, isset( $GLOBALS['ab_test_styles']['ab-mcp-admin'] ) );
}
