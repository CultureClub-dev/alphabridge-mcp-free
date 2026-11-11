<?php
/**
 * Plugin Name: AB IT Zeitstempel
 * Description: Two versions of the same size and version number with other bytes; AB_MCP_IT_Code gives every copied file the same time stamp (F39).
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: AlphaBridge integration tests
 * License: GPL-2.0-or-later
 *
 * Test plugin of the integration suite (Z8b branch 1, E21). Never shipped:
 * tests/integration/fixtures/code/ is copied into the test sites by
 * AB_MCP_IT_Code; katalog.php there says what each version is for.
 */

defined( 'ABSPATH' ) || exit;

// The mark of this version, set as it loads (F39): readable in this process
// and in the answer to every request of the site.
$GLOBALS['ab_it_fassung']['ab-it-zeitstempel'] = 'alt';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-zeitstempel: alt' );
}
