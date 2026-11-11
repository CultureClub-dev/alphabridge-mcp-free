<?php
/**
 * Plugin Name: AB IT Beobachter
 * Description: Counts every run of the hook switch_theme in the option ab_it_beobachter, also while the folder of the active theme is missing (F26). Must-use test plugin of the integration suite (Z8b branch 1, E21); never shipped.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'switch_theme',
	static function ( $new_name, $new_theme = null, $old_theme = null ) {
		$seen                 = get_option( 'ab_it_beobachter', array() );
		$seen                 = is_array( $seen ) ? $seen : array();
		$seen['switch_theme'] = ( isset( $seen['switch_theme'] ) ? (int) $seen['switch_theme'] : 0 ) + 1;
		$seen['letzter']      = array(
			'neu' => $new_theme instanceof WP_Theme ? $new_theme->get_stylesheet() : (string) $new_name,
			'alt' => $old_theme instanceof WP_Theme ? $old_theme->get_stylesheet() : '',
		);
		update_option( 'ab_it_beobachter', $seen, false );
	},
	10,
	3
);
