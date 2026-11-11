<?php
/**
 * Plugin Name: AB IT Inaktiv
 * Description: An inactive plugin (F1).
 * Version: 1.1.0
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
$GLOBALS['ab_it_fassung']['ab-it-inaktiv'] = '1.1.0';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-inaktiv: 1.1.0' );
}
