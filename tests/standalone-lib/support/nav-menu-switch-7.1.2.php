<?php
/*
 * Fixed copy for the Pro test suite only: two functions from
 * wp-includes/nav-menu.php of WordPress 7.1.2 (wp-includes/version.php:
 * $wp_version = '7.1.2'), taken from the release tag 7.1.2 of the official
 * mirror github.com/WordPress/WordPress (commit 160387b) on 04.10.2026,
 * unchanged below this comment, lines 1208-1320 of that file:
 *   _wp_menus_changed(): the after_switch_theme callback that maps the menu
 *     locations of the theme switched from onto the active one;
 *   wp_map_nav_menu_locations(): the mapping it uses.
 * The same lines are byte for byte identical in WordPress 7.0.2.
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors, https://wordpress.org/about/license/.
 */

/**
 * Handles menu config after theme change.
 *
 * @access private
 * @since 4.9.0
 */
function _wp_menus_changed() {
	$old_nav_menu_locations    = get_option( 'theme_switch_menu_locations', array() );
	$new_nav_menu_locations    = get_nav_menu_locations();
	$mapped_nav_menu_locations = wp_map_nav_menu_locations( $new_nav_menu_locations, $old_nav_menu_locations );

	set_theme_mod( 'nav_menu_locations', $mapped_nav_menu_locations );
	delete_option( 'theme_switch_menu_locations' );
}

/**
 * Maps nav menu locations according to assignments in previously active theme.
 *
 * @since 4.9.0
 *
 * @param array $new_nav_menu_locations New nav menu locations assignments.
 * @param array $old_nav_menu_locations Old nav menu locations assignments.
 * @return array Nav menus mapped to new nav menu locations.
 */
function wp_map_nav_menu_locations( $new_nav_menu_locations, $old_nav_menu_locations ) {
	$registered_nav_menus   = get_registered_nav_menus();
	$new_nav_menu_locations = array_intersect_key( $new_nav_menu_locations, $registered_nav_menus );

	// Short-circuit if there are no old nav menu location assignments to map.
	if ( empty( $old_nav_menu_locations ) ) {
		return $new_nav_menu_locations;
	}

	// If old and new theme have just one location, map it and we're done.
	if ( 1 === count( $old_nav_menu_locations ) && 1 === count( $registered_nav_menus ) ) {
		$new_nav_menu_locations[ key( $registered_nav_menus ) ] = array_pop( $old_nav_menu_locations );
		return $new_nav_menu_locations;
	}

	$old_locations = array_keys( $old_nav_menu_locations );

	// Map locations with the same slug.
	foreach ( $registered_nav_menus as $location => $name ) {
		if ( in_array( $location, $old_locations, true ) ) {
			$new_nav_menu_locations[ $location ] = $old_nav_menu_locations[ $location ];
			unset( $old_nav_menu_locations[ $location ] );
		}
	}

	// If there are no old nav menu locations left, then we're done.
	if ( empty( $old_nav_menu_locations ) ) {
		return $new_nav_menu_locations;
	}

	/*
	 * If old and new theme both have locations that contain phrases
	 * from within the same group, make an educated guess and map it.
	 */
	$common_slug_groups = array(
		array( 'primary', 'menu-1', 'main', 'header', 'navigation', 'top' ),
		array( 'secondary', 'menu-2', 'footer', 'subsidiary', 'bottom' ),
		array( 'social' ),
	);

	// Go through each group...
	foreach ( $common_slug_groups as $slug_group ) {

		// ...and see if any of these slugs...
		foreach ( $slug_group as $slug ) {

			// ...and any of the new menu locations...
			foreach ( $registered_nav_menus as $new_location => $name ) {

				// ...actually match!
				if ( is_string( $new_location ) && false === stripos( $new_location, $slug ) && false === stripos( $slug, $new_location ) ) {
					continue;
				} elseif ( is_numeric( $new_location ) && $new_location !== $slug ) {
					continue;
				}

				// Then see if any of the old locations...
				foreach ( $old_nav_menu_locations as $location => $menu_id ) {

					// ...and any slug in the same group...
					foreach ( $slug_group as $slug ) {

						// ... have a match as well.
						if ( is_string( $location ) && false === stripos( $location, $slug ) && false === stripos( $slug, $location ) ) {
							continue;
						} elseif ( is_numeric( $location ) && $location !== $slug ) {
							continue;
						}

						// Make sure this location wasn't mapped and removed previously.
						if ( ! empty( $old_nav_menu_locations[ $location ] ) ) {

							// We have a match that can be mapped!
							$new_nav_menu_locations[ $new_location ] = $old_nav_menu_locations[ $location ];

							// Remove the mapped location so it can't be mapped again.
							unset( $old_nav_menu_locations[ $location ] );

							// Go back and check the next new menu location.
							continue 3;
						}
					} // End foreach ( $slug_group as $slug ).
				} // End foreach ( $old_nav_menu_locations as $location => $menu_id ).
			} // End foreach foreach ( $registered_nav_menus as $new_location => $name ).
		} // End foreach ( $slug_group as $slug ).
	} // End foreach ( $common_slug_groups as $slug_group ).

	return $new_nav_menu_locations;
}
