<?php
/**
 * Uninstall routine of AB IT Routinen (F18): drops the table the activation
 * made and counts. Runs alone, as WordPress runs uninstall.php: the plugin
 * file is not loaded.
 *
 * @package AlphaBridge_MCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}ab_it_routinen`" );
$ab_it_zaehler                  = get_option( 'ab_it_zaehler_ab-it-routinen', array() );
$ab_it_zaehler                  = is_array( $ab_it_zaehler ) ? $ab_it_zaehler : array();
$ab_it_zaehler['deinstalliert'] = ( isset( $ab_it_zaehler['deinstalliert'] ) ? (int) $ab_it_zaehler['deinstalliert'] : 0 ) + 1;
update_option( 'ab_it_zaehler_ab-it-routinen', $ab_it_zaehler, false );
