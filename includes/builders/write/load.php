<?php
/**
 * Loading the page-builder writers: the writer base, its registry, every
 * writer in writers/, and the filter that tells the outline which tools
 * write a page. Free since 4.10.0, before in Pro only (Pro keeps its own
 * copies for older cores).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-builders.php';

/**
 * Load the writers once. Idempotent.
 *
 * @return bool True once loaded.
 */
function ab_mcp_load_builder_writers() {
	static $loaded = false;
	if ( $loaded ) {
		return true;
	}
	$dir = __DIR__ . '/';
	require_once $dir . 'class-builder-caches.php';
	require_once $dir . 'class-builder-fields.php';
	require_once $dir . 'class-builder-writer.php';
	require_once $dir . 'class-builder-writers.php';
	require_once dirname( __DIR__, 2 ) . '/undo/class-undo-builder.php';
	$writers = glob( $dir . 'writers/*.php' );
	if ( is_array( $writers ) ) {
		sort( $writers, SORT_STRING );
		foreach ( $writers as $file ) {
			require_once $file;
		}
	}
	if ( false === has_filter( 'ab_mcp_builder_write_via', array( 'AB_MCP_Builder_Writers', 'write_via' ) ) ) {
		add_filter( 'ab_mcp_builder_write_via', array( 'AB_MCP_Builder_Writers', 'write_via' ), 10, 3 );
	}
	$loaded = true;
	return true;
}

ab_mcp_load_builder_writers();
