<?php
/*
 * Fixed copy for the test suite only: the admin menu functions of WordPress
 * 7.1.3 (wp-includes/version.php: $wp_version = '7.1.3') and the helpers they
 * call, taken from the local core copy ~/Entwicklung/wp-core-7.1.3 on
 * 31.10.2026, each unchanged below its line note. AdminMenuTest registers the
 * menu «AlphaBridge» with them, as WordPress does, so the first entry that
 * add_submenu_page() adds by itself, the hook names, the entries a user may
 * not open and the access check are WordPress' own, not a stand-in's:
 *   wp-admin/includes/plugin.php: add_menu_page(), add_submenu_page(),
 *     add_options_page(), remove_submenu_page(), get_admin_page_parent(),
 *     get_plugin_page_hook(), get_plugin_page_hookname(),
 *     user_can_access_admin_page();
 *   wp-admin/includes/menu.php: the steps before and after the action
 *     admin_menu, each inside a function (see there);
 *   the helpers on the way: sanitize_title() with sanitize_title_with_dashes()
 *     (the filter of wp-includes/default-filters.php line 309, which the test
 *     adds), remove_accents(), utf8_uri_encode(), wp_is_valid_utf8(),
 *     mbstring_binary_safe_encoding(), reset_mbstring_encoding(),
 *     plugin_basename(), wp_normalize_path(), wp_is_stream(), set_url_scheme(),
 *     is_ssl();
 *   wp-includes/functions.php: add_magic_quotes(), which slashes the request
 *     the way wp_magic_quotes() does (wp-settings.php line 636);
 *   wp-includes/pluggable.php: wp_sanitize_redirect() and
 *     wp_validate_redirect() (with _wp_sanitize_utf8_in_redirect(),
 *     wp_kses_no_null(), _deep_replace()), so a test reads the Location
 *     header wp_safe_redirect() and wp_redirect() would send.
 * Not copied: _doing_it_wrong(), which add_menu_page() and add_submenu_page()
 * call only for a position that is not a number, and wp_parse_str(), which
 * the step before admin_menu calls only for a menu slug with «?»; neither
 * occurs here.
 *
 * Loaded only by the tests that run in a process of their own (AdminMenuTest):
 * sanitize_title() and the others would change what other tests see, such as
 * the undo of terms, which asks function_exists( 'sanitize_title' ).
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors, https://wordpress.org/about/license/.
 *
 * Not shipped: tests/ is export-ignored (.gitattributes) and listed in
 * .distignore. The plugin calls WordPress' own functions at run time.
 */

// The plugin folders plugin_basename() reads (wp-includes/default-constants.php
// defines them from WP_CONTENT_DIR); here below the test root.
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
}
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', ABSPATH . 'wp-content/mu-plugins' );
}

// wp-admin/includes/plugin.php lines 1398-1459 (WordPress 7.1.3).
function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	global $menu, $admin_page_hooks, $_registered_pages, $_parent_pages;

	$menu_slug = plugin_basename( $menu_slug );

	$admin_page_hooks[ $menu_slug ] = sanitize_title( $menu_title );

	$hookname = get_plugin_page_hookname( $menu_slug, '' );

	if ( ! empty( $callback ) && ! empty( $hookname ) && current_user_can( $capability ) ) {
		add_action( $hookname, $callback );
	}

	if ( empty( $icon_url ) ) {
		$icon_url   = 'dashicons-admin-generic';
		$icon_class = 'menu-icon-generic ';
	} else {
		$icon_url   = set_url_scheme( $icon_url );
		$icon_class = '';
	}

	$new_menu = array( $menu_title, $capability, $menu_slug, $page_title, 'menu-top ' . $icon_class . $hookname, $hookname, $icon_url );

	if ( null !== $position && ! is_numeric( $position ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				/* translators: %s: add_menu_page() */
				__( 'The seventh parameter passed to %s should be numeric representing menu position.' ),
				'<code>add_menu_page()</code>'
			),
			'6.0.0'
		);
		$position = null;
	}

	if ( null === $position || ! is_numeric( $position ) ) {
		$menu[] = $new_menu;
	} elseif ( isset( $menu[ (string) $position ] ) ) {
		$collision_avoider = (int) base_convert( substr( md5( $menu_slug . $menu_title ), -4 ), 16, 10 ) * 0.00001;
		$position          = (string) ( $position + $collision_avoider );
		$menu[ $position ] = $new_menu;
	} else {
		/*
		 * Cast menu position to a string.
		 *
		 * This allows for floats to be passed as the position. PHP will normally cast a float to an
		 * integer value, this ensures the float retains its mantissa (positive fractional part).
		 *
		 * A string containing an integer value, eg "10", is treated as a numeric index.
		 */
		$position          = (string) $position;
		$menu[ $position ] = $new_menu;
	}

	$_registered_pages[ $hookname ] = true;

	// No parent as top level.
	$_parent_pages[ $menu_slug ] = false;

	return $hookname;
}

// wp-admin/includes/plugin.php lines 1493-1584 (WordPress 7.1.3).
function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
	global $submenu, $menu, $_wp_real_parent_file, $_wp_submenu_nopriv,
		$_registered_pages, $_parent_pages;

	$menu_slug   = plugin_basename( $menu_slug );
	$parent_slug = plugin_basename( $parent_slug );

	if ( isset( $_wp_real_parent_file[ $parent_slug ] ) ) {
		$parent_slug = $_wp_real_parent_file[ $parent_slug ];
	}

	if ( ! current_user_can( $capability ) ) {
		$_wp_submenu_nopriv[ $parent_slug ][ $menu_slug ] = true;
		return false;
	}

	/*
	 * If the parent doesn't already have a submenu, add a link to the parent
	 * as the first item in the submenu. If the submenu file is the same as the
	 * parent file someone is trying to link back to the parent manually. In
	 * this case, don't automatically add a link back to avoid duplication.
	 */
	if ( ! isset( $submenu[ $parent_slug ] ) && $menu_slug !== $parent_slug ) {
		foreach ( (array) $menu as $parent_menu ) {
			if ( $parent_menu[2] === $parent_slug && current_user_can( $parent_menu[1] ) ) {
				$submenu[ $parent_slug ][] = array_slice( $parent_menu, 0, 4 );
			}
		}
	}

	$new_sub_menu = array( $menu_title, $capability, $menu_slug, $page_title );

	if ( null !== $position && ! is_numeric( $position ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				/* translators: %s: add_submenu_page() */
				__( 'The seventh parameter passed to %s should be numeric representing menu position.' ),
				'<code>add_submenu_page()</code>'
			),
			'5.3.0'
		);
		$position = null;
	}

	if (
		null === $position ||
		( ! isset( $submenu[ $parent_slug ] ) || $position >= count( $submenu[ $parent_slug ] ) )
	) {
		$submenu[ $parent_slug ][] = $new_sub_menu;
	} else {
		// Test for a negative position.
		$position = max( $position, 0 );
		if ( 0 === $position ) {
			// For negative or `0` positions, prepend the submenu.
			array_unshift( $submenu[ $parent_slug ], $new_sub_menu );
		} else {
			$position = absint( $position );
			// Grab all of the items before the insertion point.
			$before_items = array_slice( $submenu[ $parent_slug ], 0, $position, true );
			// Grab all of the items after the insertion point.
			$after_items = array_slice( $submenu[ $parent_slug ], $position, null, true );
			// Add the new item.
			$before_items[] = $new_sub_menu;
			// Merge the items.
			$submenu[ $parent_slug ] = array_merge( $before_items, $after_items );
		}
	}

	// Sort the parent array.
	ksort( $submenu[ $parent_slug ] );

	$hookname = get_plugin_page_hookname( $menu_slug, $parent_slug );
	if ( ! empty( $callback ) && ! empty( $hookname ) ) {
		add_action( $hookname, $callback );
	}

	$_registered_pages[ $hookname ] = true;

	/*
	 * Backward-compatibility for plugins using add_management_page().
	 * See wp-admin/admin.php for redirect from edit.php to tools.php.
	 */
	if ( 'tools.php' === $parent_slug ) {
		$_registered_pages[ get_plugin_page_hookname( $menu_slug, 'edit.php' ) ] = true;
	}

	// No parent as top level.
	$_parent_pages[ $menu_slug ] = $parent_slug;

	return $hookname;
}

// wp-admin/includes/plugin.php lines 1630-1632 (WordPress 7.1.3).
function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
	return add_submenu_page( 'options-general.php', $page_title, $menu_title, $capability, $menu_slug, $callback, $position );
}

// wp-admin/includes/plugin.php lines 1900-1915 (WordPress 7.1.3).
function remove_submenu_page( $menu_slug, $submenu_slug ) {
	global $submenu;

	if ( ! isset( $submenu[ $menu_slug ] ) ) {
		return false;
	}

	foreach ( $submenu[ $menu_slug ] as $i => $item ) {
		if ( $submenu_slug === $item[2] ) {
			unset( $submenu[ $menu_slug ][ $i ] );
			return $item;
		}
	}

	return false;
}

// wp-admin/includes/plugin.php lines 1976-2027 (WordPress 7.1.3).
function get_admin_page_parent( $parent_page = '' ) {
	global $parent_file, $menu, $submenu, $pagenow, $typenow,
		$plugin_page, $_wp_real_parent_file, $_wp_menu_nopriv, $_wp_submenu_nopriv;

	if ( ! empty( $parent_page ) && 'admin.php' !== $parent_page ) {
		return $_wp_real_parent_file[ $parent_page ] ?? $parent_page;
	}

	if ( 'admin.php' === $pagenow && isset( $plugin_page ) ) {
		foreach ( (array) $menu as $parent_menu ) {
			if ( $parent_menu[2] === $plugin_page ) {
				$parent_file = $plugin_page;
				return $_wp_real_parent_file[ $parent_file ] ?? $parent_file;
			}
		}
		if ( isset( $_wp_menu_nopriv[ $plugin_page ] ) ) {
			$parent_file = $plugin_page;
			return $_wp_real_parent_file[ $parent_file ] ?? $parent_file;
		}
	}

	if ( isset( $plugin_page ) && isset( $_wp_submenu_nopriv[ $pagenow ][ $plugin_page ] ) ) {
		$parent_file = $pagenow;
		return $_wp_real_parent_file[ $parent_file ] ?? $parent_file;
	}

	foreach ( array_keys( (array) $submenu ) as $parent_page ) {
		foreach ( $submenu[ $parent_page ] as $submenu_array ) {
			if ( isset( $_wp_real_parent_file[ $parent_page ] ) ) {
				$parent_page = $_wp_real_parent_file[ $parent_page ];
			}

			if ( ! empty( $typenow ) && "$pagenow?post_type=$typenow" === $submenu_array[2] ) {
				$parent_file = $parent_page;
				return $parent_page;
			} elseif ( empty( $typenow ) && $pagenow === $submenu_array[2]
				&& ( empty( $parent_file ) || ! str_contains( $parent_file, '?' ) )
			) {
				$parent_file = $parent_page;
				return $parent_page;
			} elseif ( isset( $plugin_page ) && $plugin_page === $submenu_array[2] ) {
				$parent_file = $parent_page;
				return $parent_page;
			}
		}
	}

	if ( empty( $parent_file ) ) {
		$parent_file = '';
	}
	return '';
}

// wp-admin/includes/plugin.php lines 2125-2132 (WordPress 7.1.3).
function get_plugin_page_hook( $plugin_page, $parent_page ) {
	$hook = get_plugin_page_hookname( $plugin_page, $parent_page );
	if ( has_action( $hook ) ) {
		return $hook;
	} else {
		return null;
	}
}

// wp-admin/includes/plugin.php lines 2146-2165 (WordPress 7.1.3).
function get_plugin_page_hookname( $plugin_page, $parent_page ) {
	global $admin_page_hooks;

	$parent = get_admin_page_parent( $parent_page );

	$page_type = 'admin';
	if ( empty( $parent_page ) || 'admin.php' === $parent_page || isset( $admin_page_hooks[ $plugin_page ] ) ) {
		if ( isset( $admin_page_hooks[ $plugin_page ] ) ) {
			$page_type = 'toplevel';
		} elseif ( isset( $admin_page_hooks[ $parent ] ) ) {
			$page_type = $admin_page_hooks[ $parent ];
		}
	} elseif ( isset( $admin_page_hooks[ $parent ] ) ) {
		$page_type = $admin_page_hooks[ $parent ];
	}

	$plugin_name = preg_replace( '!\.php!', '', $plugin_page );

	return $page_type . '_page_' . $plugin_name;
}

// wp-admin/includes/plugin.php lines 2182-2251 (WordPress 7.1.3).
function user_can_access_admin_page() {
	global $pagenow, $menu, $submenu, $_wp_menu_nopriv, $_wp_submenu_nopriv,
		$plugin_page, $_registered_pages;

	$parent = get_admin_page_parent();

	if ( ! isset( $plugin_page ) && isset( $_wp_submenu_nopriv[ $parent ][ $pagenow ] ) ) {
		return false;
	}

	if ( isset( $plugin_page ) ) {
		if ( isset( $_wp_submenu_nopriv[ $parent ][ $plugin_page ] ) ) {
			return false;
		}

		$hookname = get_plugin_page_hookname( $plugin_page, $parent );

		if ( ! isset( $_registered_pages[ $hookname ] ) ) {
			return false;
		}
	}

	if ( empty( $parent ) ) {
		if ( isset( $_wp_menu_nopriv[ $pagenow ] ) ) {
			return false;
		}
		if ( isset( $_wp_submenu_nopriv[ $pagenow ][ $pagenow ] ) ) {
			return false;
		}
		if ( isset( $plugin_page ) && isset( $_wp_submenu_nopriv[ $pagenow ][ $plugin_page ] ) ) {
			return false;
		}
		if ( isset( $plugin_page ) && isset( $_wp_menu_nopriv[ $plugin_page ] ) ) {
			return false;
		}

		foreach ( array_keys( $_wp_submenu_nopriv ) as $key ) {
			if ( isset( $_wp_submenu_nopriv[ $key ][ $pagenow ] ) ) {
				return false;
			}
			if ( isset( $plugin_page ) && isset( $_wp_submenu_nopriv[ $key ][ $plugin_page ] ) ) {
				return false;
			}
		}

		return true;
	}

	if ( isset( $plugin_page ) && $plugin_page === $parent && isset( $_wp_menu_nopriv[ $plugin_page ] ) ) {
		return false;
	}

	if ( isset( $submenu[ $parent ] ) ) {
		foreach ( $submenu[ $parent ] as $submenu_array ) {
			if ( isset( $plugin_page ) && $submenu_array[2] === $plugin_page ) {
				return current_user_can( $submenu_array[1] );
			} elseif ( $submenu_array[2] === $pagenow ) {
				return current_user_can( $submenu_array[1] );
			}
		}
	}

	foreach ( $menu as $menu_array ) {
		if ( $menu_array[2] === $parent ) {
			return current_user_can( $menu_array[1] );
		}
	}

	return true;
}

/**
 * wp-admin/includes/menu.php lines 51-137 (WordPress 7.1.3), unchanged inside a
 * function: what WordPress does with the menu of the core before the
 * action admin_menu (the names of the menu hooks, the entries a user may
 * not open, the first entry as parent). At file scope there, so the
 * function names the globals the block reads and writes.
 */
function ab_test_core_menu_before_admin_menu() {
	global $menu, $submenu, $compat, $admin_page_hooks, $_wp_submenu_nopriv, $_wp_menu_nopriv, $_wp_real_parent_file;

	// Create list of page plugin hook names.
	foreach ( $menu as $menu_page ) {
		$pos = strpos( $menu_page[2], '?' );

		if ( false !== $pos ) {
			// Handle post_type=post|page|foo pages.
			$hook_name = substr( $menu_page[2], 0, $pos );
			$hook_args = substr( $menu_page[2], $pos + 1 );
			wp_parse_str( $hook_args, $hook_args );

			// Set the hook name to be the post type.
			if ( isset( $hook_args['post_type'] ) ) {
				$hook_name = $hook_args['post_type'];
			} else {
				$hook_name = basename( $hook_name, '.php' );
			}
			unset( $hook_args );
		} else {
			$hook_name = basename( $menu_page[2], '.php' );
		}

		$hook_name = sanitize_title( $hook_name );

		if ( isset( $compat[ $hook_name ] ) ) {
			$hook_name = $compat[ $hook_name ];
		} elseif ( ! $hook_name ) {
			continue;
		}

		$admin_page_hooks[ $menu_page[2] ] = $hook_name;
	}
	unset( $menu_page, $compat );

	$_wp_submenu_nopriv = array();
	$_wp_menu_nopriv    = array();
	// Loop over submenus and remove pages for which the user does not have privs.
	foreach ( $submenu as $parent => $sub ) {
		foreach ( $sub as $index => $data ) {
			if ( ! current_user_can( $data[1] ) ) {
				unset( $submenu[ $parent ][ $index ] );
				$_wp_submenu_nopriv[ $parent ][ $data[2] ] = true;
			}
		}
		unset( $index, $data );

		if ( empty( $submenu[ $parent ] ) ) {
			unset( $submenu[ $parent ] );
		}
	}
	unset( $sub, $parent );

	/*
	 * Loop over the top-level menu.
	 * Menus for which the original parent is not accessible due to lack of privileges
	 * will have the next submenu in line be assigned as the new menu parent.
	 */
	foreach ( $menu as $id => $data ) {
		if ( empty( $submenu[ $data[2] ] ) ) {
			continue;
		}

		$subs       = $submenu[ $data[2] ];
		$first_sub  = reset( $subs );
		$old_parent = $data[2];
		$new_parent = $first_sub[2];

		/*
		 * If the first submenu is not the same as the assigned parent,
		 * make the first submenu the new parent.
		 */
		if ( $new_parent !== $old_parent ) {
			$_wp_real_parent_file[ $old_parent ] = $new_parent;

			$menu[ $id ][2] = $new_parent;

			foreach ( $submenu[ $old_parent ] as $index => $data ) {
				$submenu[ $new_parent ][ $index ] = $submenu[ $old_parent ][ $index ];
				unset( $submenu[ $old_parent ][ $index ] );
			}
			unset( $submenu[ $old_parent ], $index );

			if ( isset( $_wp_submenu_nopriv[ $old_parent ] ) ) {
				$_wp_submenu_nopriv[ $new_parent ] = $_wp_submenu_nopriv[ $old_parent ];
			}
		}
	}
	unset( $id, $data, $subs, $first_sub, $old_parent, $new_parent );
}

/**
 * wp-admin/includes/menu.php lines 171-201 (WordPress 7.1.3), unchanged inside a
 * function: what WordPress does with the menu right after the action
 * admin_menu (a menu without an entry the user may open goes). The access
 * check follows in WordPress (menu.php line 375, user_can_access_admin_page()).
 */
function ab_test_core_menu_after_admin_menu() {
	global $menu, $submenu, $compat, $admin_page_hooks, $_wp_submenu_nopriv, $_wp_menu_nopriv, $_wp_real_parent_file;

	/*
	 * Remove menus that have no accessible submenus and require privileges
	 * that the user does not have. Run re-parent loop again.
	 */
	foreach ( $menu as $id => $data ) {
		if ( ! current_user_can( $data[1] ) ) {
			$_wp_menu_nopriv[ $data[2] ] = true;
		}

		/*
		 * If there is only one submenu and it is has same destination as the parent,
		 * remove the submenu.
		 */
		if ( ! empty( $submenu[ $data[2] ] ) && 1 === count( $submenu[ $data[2] ] ) ) {
			$subs      = $submenu[ $data[2] ];
			$first_sub = reset( $subs );

			if ( $data[2] === $first_sub[2] ) {
				unset( $submenu[ $data[2] ] );
			}
		}

		// If submenu is empty...
		if ( empty( $submenu[ $data[2] ] ) ) {
			// And user doesn't have privs, remove menu.
			if ( isset( $_wp_menu_nopriv[ $data[2] ] ) ) {
				unset( $menu[ $id ] );
			}
		}
	}
	unset( $id, $data, $subs, $first_sub );
}

// wp-includes/formatting.php lines 1160-1213 (WordPress 7.1.3).
function utf8_uri_encode( $utf8_string, $length = 0, $encode_ascii_characters = false ) {
	$unicode        = '';
	$values         = array();
	$num_octets     = 1;
	$unicode_length = 0;

	mbstring_binary_safe_encoding();
	$string_length = strlen( $utf8_string );
	reset_mbstring_encoding();

	for ( $i = 0; $i < $string_length; $i++ ) {

		$value = ord( $utf8_string[ $i ] );

		if ( $value < 128 ) {
			$char                = chr( $value );
			$encoded_char        = $encode_ascii_characters ? rawurlencode( $char ) : $char;
			$encoded_char_length = strlen( $encoded_char );
			if ( $length && ( $unicode_length + $encoded_char_length ) > $length ) {
				break;
			}
			$unicode        .= $encoded_char;
			$unicode_length += $encoded_char_length;
		} else {
			if ( 0 === count( $values ) ) {
				if ( $value < 224 ) {
					$num_octets = 2;
				} elseif ( $value < 240 ) {
					$num_octets = 3;
				} else {
					$num_octets = 4;
				}
			}

			$values[] = $value;

			if ( $length && ( $unicode_length + ( $num_octets * 3 ) ) > $length ) {
				break;
			}
			if ( count( $values ) === $num_octets ) {
				for ( $j = 0; $j < $num_octets; $j++ ) {
					$unicode .= '%' . dechex( $values[ $j ] );
				}

				$unicode_length += $num_octets * 3;

				$values     = array();
				$num_octets = 1;
			}
		}
	}

	return $unicode;
}

// wp-includes/formatting.php lines 1611-2018 (WordPress 7.1.3).
function remove_accents( $text, $locale = '' ) {
	if ( ! preg_match( '/[\x80-\xff]/', $text ) ) {
		return $text;
	}

	if ( wp_is_valid_utf8( $text ) ) {

		/*
		 * Unicode sequence normalization from NFD (Normalization Form Decomposed)
		 * to NFC (Normalization Form [Pre]Composed), the encoding used in this function.
		 */
		if ( function_exists( 'normalizer_is_normalized' )
			&& function_exists( 'normalizer_normalize' )
		) {
			if ( ! normalizer_is_normalized( $text ) ) {
				$text = normalizer_normalize( $text );
			}
		}

		$chars = array(
			// Decompositions for Latin-1 Supplement.
			'ª' => 'a',
			'º' => 'o',
			'À' => 'A',
			'Á' => 'A',
			'Â' => 'A',
			'Ã' => 'A',
			'Ä' => 'A',
			'Å' => 'A',
			'Æ' => 'AE',
			'Ç' => 'C',
			'È' => 'E',
			'É' => 'E',
			'Ê' => 'E',
			'Ë' => 'E',
			'Ì' => 'I',
			'Í' => 'I',
			'Î' => 'I',
			'Ï' => 'I',
			'Ð' => 'D',
			'Ñ' => 'N',
			'Ò' => 'O',
			'Ó' => 'O',
			'Ô' => 'O',
			'Õ' => 'O',
			'Ö' => 'O',
			'Ù' => 'U',
			'Ú' => 'U',
			'Û' => 'U',
			'Ü' => 'U',
			'Ý' => 'Y',
			'Þ' => 'TH',
			'ß' => 's',
			'à' => 'a',
			'á' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'ä' => 'a',
			'å' => 'a',
			'æ' => 'ae',
			'ç' => 'c',
			'è' => 'e',
			'é' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'ì' => 'i',
			'í' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ð' => 'd',
			'ñ' => 'n',
			'ò' => 'o',
			'ó' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ö' => 'o',
			'ø' => 'o',
			'ù' => 'u',
			'ú' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ý' => 'y',
			'þ' => 'th',
			'ÿ' => 'y',
			'Ø' => 'O',
			// Decompositions for Latin Extended-A.
			'Ā' => 'A',
			'ā' => 'a',
			'Ă' => 'A',
			'ă' => 'a',
			'Ą' => 'A',
			'ą' => 'a',
			'Ć' => 'C',
			'ć' => 'c',
			'Ĉ' => 'C',
			'ĉ' => 'c',
			'Ċ' => 'C',
			'ċ' => 'c',
			'Č' => 'C',
			'č' => 'c',
			'Ď' => 'D',
			'ď' => 'd',
			'Đ' => 'D',
			'đ' => 'd',
			'Ē' => 'E',
			'ē' => 'e',
			'Ĕ' => 'E',
			'ĕ' => 'e',
			'Ė' => 'E',
			'ė' => 'e',
			'Ę' => 'E',
			'ę' => 'e',
			'Ě' => 'E',
			'ě' => 'e',
			'Ĝ' => 'G',
			'ĝ' => 'g',
			'Ğ' => 'G',
			'ğ' => 'g',
			'Ġ' => 'G',
			'ġ' => 'g',
			'Ģ' => 'G',
			'ģ' => 'g',
			'Ĥ' => 'H',
			'ĥ' => 'h',
			'Ħ' => 'H',
			'ħ' => 'h',
			'Ĩ' => 'I',
			'ĩ' => 'i',
			'Ī' => 'I',
			'ī' => 'i',
			'Ĭ' => 'I',
			'ĭ' => 'i',
			'Į' => 'I',
			'į' => 'i',
			'İ' => 'I',
			'ı' => 'i',
			'Ĳ' => 'IJ',
			'ĳ' => 'ij',
			'Ĵ' => 'J',
			'ĵ' => 'j',
			'Ķ' => 'K',
			'ķ' => 'k',
			'ĸ' => 'k',
			'Ĺ' => 'L',
			'ĺ' => 'l',
			'Ļ' => 'L',
			'ļ' => 'l',
			'Ľ' => 'L',
			'ľ' => 'l',
			'Ŀ' => 'L',
			'ŀ' => 'l',
			'Ł' => 'L',
			'ł' => 'l',
			'Ń' => 'N',
			'ń' => 'n',
			'Ņ' => 'N',
			'ņ' => 'n',
			'Ň' => 'N',
			'ň' => 'n',
			'ŉ' => 'n',
			'Ŋ' => 'N',
			'ŋ' => 'n',
			'Ō' => 'O',
			'ō' => 'o',
			'Ŏ' => 'O',
			'ŏ' => 'o',
			'Ő' => 'O',
			'ő' => 'o',
			'Œ' => 'OE',
			'œ' => 'oe',
			'Ŕ' => 'R',
			'ŕ' => 'r',
			'Ŗ' => 'R',
			'ŗ' => 'r',
			'Ř' => 'R',
			'ř' => 'r',
			'Ś' => 'S',
			'ś' => 's',
			'Ŝ' => 'S',
			'ŝ' => 's',
			'Ş' => 'S',
			'ş' => 's',
			'Š' => 'S',
			'š' => 's',
			'Ţ' => 'T',
			'ţ' => 't',
			'Ť' => 'T',
			'ť' => 't',
			'Ŧ' => 'T',
			'ŧ' => 't',
			'Ũ' => 'U',
			'ũ' => 'u',
			'Ū' => 'U',
			'ū' => 'u',
			'Ŭ' => 'U',
			'ŭ' => 'u',
			'Ů' => 'U',
			'ů' => 'u',
			'Ű' => 'U',
			'ű' => 'u',
			'Ų' => 'U',
			'ų' => 'u',
			'Ŵ' => 'W',
			'ŵ' => 'w',
			'Ŷ' => 'Y',
			'ŷ' => 'y',
			'Ÿ' => 'Y',
			'Ź' => 'Z',
			'ź' => 'z',
			'Ż' => 'Z',
			'ż' => 'z',
			'Ž' => 'Z',
			'ž' => 'z',
			'ſ' => 's',
			// Decompositions for Latin Extended-B.
			'Ə' => 'E',
			'ǝ' => 'e',
			'Ș' => 'S',
			'ș' => 's',
			'Ț' => 'T',
			'ț' => 't',
			// Euro sign.
			'€' => 'E',
			// GBP (Pound) sign.
			'£' => '',
			// Vowels with diacritic (Vietnamese). Unmarked.
			'Ơ' => 'O',
			'ơ' => 'o',
			'Ư' => 'U',
			'ư' => 'u',
			// Grave accent.
			'Ầ' => 'A',
			'ầ' => 'a',
			'Ằ' => 'A',
			'ằ' => 'a',
			'Ề' => 'E',
			'ề' => 'e',
			'Ồ' => 'O',
			'ồ' => 'o',
			'Ờ' => 'O',
			'ờ' => 'o',
			'Ừ' => 'U',
			'ừ' => 'u',
			'Ỳ' => 'Y',
			'ỳ' => 'y',
			// Hook.
			'Ả' => 'A',
			'ả' => 'a',
			'Ẩ' => 'A',
			'ẩ' => 'a',
			'Ẳ' => 'A',
			'ẳ' => 'a',
			'Ẻ' => 'E',
			'ẻ' => 'e',
			'Ể' => 'E',
			'ể' => 'e',
			'Ỉ' => 'I',
			'ỉ' => 'i',
			'Ỏ' => 'O',
			'ỏ' => 'o',
			'Ổ' => 'O',
			'ổ' => 'o',
			'Ở' => 'O',
			'ở' => 'o',
			'Ủ' => 'U',
			'ủ' => 'u',
			'Ử' => 'U',
			'ử' => 'u',
			'Ỷ' => 'Y',
			'ỷ' => 'y',
			// Tilde.
			'Ẫ' => 'A',
			'ẫ' => 'a',
			'Ẵ' => 'A',
			'ẵ' => 'a',
			'Ẽ' => 'E',
			'ẽ' => 'e',
			'Ễ' => 'E',
			'ễ' => 'e',
			'Ỗ' => 'O',
			'ỗ' => 'o',
			'Ỡ' => 'O',
			'ỡ' => 'o',
			'Ữ' => 'U',
			'ữ' => 'u',
			'Ỹ' => 'Y',
			'ỹ' => 'y',
			// Acute accent.
			'Ấ' => 'A',
			'ấ' => 'a',
			'Ắ' => 'A',
			'ắ' => 'a',
			'Ế' => 'E',
			'ế' => 'e',
			'Ố' => 'O',
			'ố' => 'o',
			'Ớ' => 'O',
			'ớ' => 'o',
			'Ứ' => 'U',
			'ứ' => 'u',
			// Dot below.
			'Ạ' => 'A',
			'ạ' => 'a',
			'Ậ' => 'A',
			'ậ' => 'a',
			'Ặ' => 'A',
			'ặ' => 'a',
			'Ẹ' => 'E',
			'ẹ' => 'e',
			'Ệ' => 'E',
			'ệ' => 'e',
			'Ị' => 'I',
			'ị' => 'i',
			'Ọ' => 'O',
			'ọ' => 'o',
			'Ộ' => 'O',
			'ộ' => 'o',
			'Ợ' => 'O',
			'ợ' => 'o',
			'Ụ' => 'U',
			'ụ' => 'u',
			'Ự' => 'U',
			'ự' => 'u',
			'Ỵ' => 'Y',
			'ỵ' => 'y',
			// Vowels with diacritic (Chinese, Hanyu Pinyin).
			'ɑ' => 'a',
			// Macron.
			'Ǖ' => 'U',
			'ǖ' => 'u',
			// Acute accent.
			'Ǘ' => 'U',
			'ǘ' => 'u',
			// Caron.
			'Ǎ' => 'A',
			'ǎ' => 'a',
			'Ǐ' => 'I',
			'ǐ' => 'i',
			'Ǒ' => 'O',
			'ǒ' => 'o',
			'Ǔ' => 'U',
			'ǔ' => 'u',
			'Ǚ' => 'U',
			'ǚ' => 'u',
			// Grave accent.
			'Ǜ' => 'U',
			'ǜ' => 'u',
		);

		// Used for locale-specific rules.
		if ( empty( $locale ) ) {
			$locale = get_locale();
		}

		/*
		 * German has various locales (de_DE, de_CH, de_AT, ...) with formal and informal variants.
		 * There is no 3-letter locale like 'def', so checking for 'de' instead of 'de_' is safe,
		 * since 'de' itself would be a valid locale too.
		 */
		if ( str_starts_with( $locale, 'de' ) ) {
			$chars['Ä'] = 'Ae';
			$chars['ä'] = 'ae';
			$chars['Ö'] = 'Oe';
			$chars['ö'] = 'oe';
			$chars['Ü'] = 'Ue';
			$chars['ü'] = 'ue';
			$chars['ẞ'] = 'SS';
			$chars['ß'] = 'ss';
		} elseif ( 'da_DK' === $locale ) {
			$chars['Æ'] = 'Ae';
			$chars['æ'] = 'ae';
			$chars['Ø'] = 'Oe';
			$chars['ø'] = 'oe';
			$chars['Å'] = 'Aa';
			$chars['å'] = 'aa';
		} elseif ( 'ca' === $locale ) {
			$chars['l·l'] = 'll';
		} elseif ( 'sr_RS' === $locale || 'bs_BA' === $locale ) {
			$chars['Đ'] = 'DJ';
			$chars['đ'] = 'dj';
		}

		$text = strtr( $text, $chars );
	} else {
		$chars = array();
		// Assume ISO-8859-1 if not UTF-8.
		$chars['in'] = "\x80\x83\x8a\x8e\x9a\x9e"
			. "\x9f\xa2\xa5\xb5\xc0\xc1\xc2"
			. "\xc3\xc4\xc5\xc7\xc8\xc9\xca"
			. "\xcb\xcc\xcd\xce\xcf\xd1\xd2"
			. "\xd3\xd4\xd5\xd6\xd8\xd9\xda"
			. "\xdb\xdc\xdd\xe0\xe1\xe2\xe3"
			. "\xe4\xe5\xe7\xe8\xe9\xea\xeb"
			. "\xec\xed\xee\xef\xf1\xf2\xf3"
			. "\xf4\xf5\xf6\xf8\xf9\xfa\xfb"
			. "\xfc\xfd\xff";

		$chars['out'] = 'EfSZszYcYuAAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy';

		$text                = strtr( $text, $chars['in'], $chars['out'] );
		$double_chars        = array();
		$double_chars['in']  = array( "\x8c", "\x9c", "\xc6", "\xd0", "\xde", "\xdf", "\xe6", "\xf0", "\xfe" );
		$double_chars['out'] = array( 'OE', 'oe', 'AE', 'DH', 'TH', 'ss', 'ae', 'dh', 'th' );
		$text                = str_replace( $double_chars['in'], $double_chars['out'], $text );
	}

	return $text;
}

// wp-includes/formatting.php lines 2228-2251 (WordPress 7.1.3).
function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
	$raw_title = $title;

	if ( 'save' === $context ) {
		$title = remove_accents( $title );
	}

	/**
	 * Filters a sanitized title string.
	 *
	 * @since 1.2.0
	 *
	 * @param string $title     Sanitized title.
	 * @param string $raw_title The title prior to sanitization.
	 * @param string $context   The context for which the title is being sanitized.
	 */
	$title = apply_filters( 'sanitize_title', $title, $raw_title, $context );

	if ( '' === $title || false === $title ) {
		$title = $fallback_title;
	}

	return $title;
}

// wp-includes/formatting.php lines 2282-2401 (WordPress 7.1.3).
function sanitize_title_with_dashes( $title, $raw_title = '', $context = 'display' ) {
	$title = strip_tags( $title );
	// Preserve escaped octets.
	$title = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title );
	// Remove percent signs that are not part of an octet.
	$title = str_replace( '%', '', $title );
	// Restore octets.
	$title = preg_replace( '|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title );

	if ( wp_is_valid_utf8( $title ) ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			$title = mb_strtolower( $title, 'UTF-8' );
		}
		$title = utf8_uri_encode( $title, 200 );
	}

	$title = strtolower( $title );

	if ( 'save' === $context ) {
		// Convert &nbsp, non-breaking hyphen, &ndash, and &mdash to hyphens.
		$title = str_replace( array( '%c2%a0', '%e2%80%91', '%e2%80%93', '%e2%80%94' ), '-', $title );
		// Convert &nbsp, non-breaking hyphen, &ndash, and &mdash HTML entities to hyphens.
		$title = str_replace( array( '&nbsp;', '&#8209;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;' ), '-', $title );
		// Convert forward slash to hyphen.
		$title = str_replace( '/', '-', $title );

		// Strip these characters entirely.
		$title = str_replace(
			array(
				// Soft hyphens.
				'%c2%ad',
				// &iexcl and &iquest.
				'%c2%a1',
				'%c2%bf',
				// Angle quotes.
				'%c2%ab',
				'%c2%bb',
				'%e2%80%b9',
				'%e2%80%ba',
				// Curly quotes.
				'%e2%80%98',
				'%e2%80%99',
				'%e2%80%9c',
				'%e2%80%9d',
				'%e2%80%9a',
				'%e2%80%9b',
				'%e2%80%9e',
				'%e2%80%9f',
				// Bullet.
				'%e2%80%a2',
				// &copy, &reg, &deg, &hellip, and &trade.
				'%c2%a9',
				'%c2%ae',
				'%c2%b0',
				'%e2%80%a6',
				'%e2%84%a2',
				// Acute accents.
				'%c2%b4',
				'%cb%8a',
				'%cc%81',
				'%cd%81',
				// Grave accent, macron, caron.
				'%cc%80',
				'%cc%84',
				'%cc%8c',
				// Non-visible characters that display without a width.
				'%e2%80%8b', // Zero width space.
				'%e2%80%8c', // Zero width non-joiner.
				'%e2%80%8d', // Zero width joiner.
				'%e2%80%8e', // Left-to-right mark.
				'%e2%80%8f', // Right-to-left mark.
				'%e2%80%aa', // Left-to-right embedding.
				'%e2%80%ab', // Right-to-left embedding.
				'%e2%80%ac', // Pop directional formatting.
				'%e2%80%ad', // Left-to-right override.
				'%e2%80%ae', // Right-to-left override.
				'%ef%bb%bf', // Byte order mark.
				'%ef%bf%bc', // Object replacement character.
			),
			'',
			$title
		);

		// Convert non-visible characters that display with a width to hyphen.
		$title = str_replace(
			array(
				'%e2%80%80', // En quad.
				'%e2%80%81', // Em quad.
				'%e2%80%82', // En space.
				'%e2%80%83', // Em space.
				'%e2%80%84', // Three-per-em space.
				'%e2%80%85', // Four-per-em space.
				'%e2%80%86', // Six-per-em space.
				'%e2%80%87', // Figure space.
				'%e2%80%88', // Punctuation space.
				'%e2%80%89', // Thin space.
				'%e2%80%8a', // Hair space.
				'%e2%80%a8', // Line separator.
				'%e2%80%a9', // Paragraph separator.
				'%e2%80%af', // Narrow no-break space.
			),
			'-',
			$title
		);

		// Convert &times to 'x'.
		$title = str_replace( '%c3%97', 'x', $title );
	}

	// Remove HTML entities.
	$title = preg_replace( '/&.+?;/', '', $title );
	$title = str_replace( '.', '-', $title );

	$title = preg_replace( '/[^%a-z0-9 _-]/', '', $title );
	$title = preg_replace( '/\s+/', '-', $title );
	$title = preg_replace( '|-+|', '-', $title );
	$title = trim( $title, '-' );

	return $title;
}

// wp-includes/formatting.php lines 4497-4506 (WordPress 7.1.3).
function _deep_replace( $search, $subject ) {
	$subject = (string) $subject;

	$count = 1;
	while ( $count ) {
		$subject = str_replace( $search, '', $subject, $count );
	}

	return $subject;
}

// wp-includes/utf8.php lines 39-41 (WordPress 7.1.3), the branch with mbstring.
	function wp_is_valid_utf8( string $bytes ): bool {
		return mb_check_encoding( $bytes, 'UTF-8' );
	}

// wp-includes/functions.php lines 1297-1307 (WordPress 7.1.3).
function add_magic_quotes( $input_array ) {
	foreach ( (array) $input_array as $k => $v ) {
		if ( is_array( $v ) ) {
			$input_array[ $k ] = add_magic_quotes( $v );
		} elseif ( is_string( $v ) ) {
			$input_array[ $k ] = addslashes( $v );
		}
	}

	return $input_array;
}

// wp-includes/functions.php lines 2194-2224 (WordPress 7.1.3).
function wp_normalize_path( $path ): string {
	$path = (string) $path;

	static $cache = array();
	if ( isset( $cache[ $path ] ) ) {
		return $cache[ $path ];
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );

		$wrapper .= '://';
	}

	// Standardize all paths to use '/'.
	$path = str_replace( '\\', '/', $path );

	// Replace multiple slashes down to a singular, allowing for network shares having two slashes.
	$path = (string) preg_replace( '|(?<=.)/+|', '/', $path );

	// Windows paths should uppercase the drive letter.
	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$cache[ $original_path ] = $wrapper . $path;
	return $cache[ $original_path ];
}

// wp-includes/functions.php lines 7501-7512 (WordPress 7.1.3).
function wp_is_stream( $path ) {
	$scheme_separator = strpos( $path, '://' );

	if ( false === $scheme_separator ) {
		// $path isn't a stream.
		return false;
	}

	$stream = substr( $path, 0, $scheme_separator );

	return in_array( $stream, stream_get_wrappers(), true );
}

// wp-includes/functions.php lines 7778-7806 (WordPress 7.1.3).
function mbstring_binary_safe_encoding( $reset = false ) {
	static $encodings  = array();
	static $overloaded = null;

	if ( is_null( $overloaded ) ) {
		if ( function_exists( 'mb_internal_encoding' )
			&& ( (int) ini_get( 'mbstring.func_overload' ) & 2 ) // phpcs:ignore PHPCompatibility.IniDirectives.RemovedIniDirectives.mbstring_func_overloadDeprecated
		) {
			$overloaded = true;
		} else {
			$overloaded = false;
		}
	}

	if ( false === $overloaded ) {
		return;
	}

	if ( ! $reset ) {
		$encoding = mb_internal_encoding();
		array_push( $encodings, $encoding );
		mb_internal_encoding( 'ISO-8859-1' );
	}

	if ( $reset && $encodings ) {
		$encoding = array_pop( $encodings );
		mb_internal_encoding( $encoding );
	}
}

// wp-includes/functions.php lines 7815-7817 (WordPress 7.1.3).
function reset_mbstring_encoding() {
	mbstring_binary_safe_encoding( true );
}

// wp-includes/plugin.php lines 776-797 (WordPress 7.1.3).
function plugin_basename( $file ) {
	global $wp_plugin_paths;

	// $wp_plugin_paths contains normalized paths.
	$file = wp_normalize_path( $file );

	arsort( $wp_plugin_paths );

	foreach ( $wp_plugin_paths as $dir => $realdir ) {
		if ( str_starts_with( $file, $realdir ) ) {
			$file = $dir . substr( $file, strlen( $realdir ) );
		}
	}

	$plugin_dir    = wp_normalize_path( WP_PLUGIN_DIR );
	$mu_plugin_dir = wp_normalize_path( WPMU_PLUGIN_DIR );

	// Get relative path from plugins directory.
	$file = preg_replace( '#^' . preg_quote( $plugin_dir, '#' ) . '/|^' . preg_quote( $mu_plugin_dir, '#' ) . '/#', '', $file );
	$file = trim( $file, '/' );
	return $file;
}

// wp-includes/link-template.php lines 3930-3966 (WordPress 7.1.3).
function set_url_scheme( $url, $scheme = null ) {
	$orig_scheme = $scheme;

	if ( ! $scheme ) {
		$scheme = is_ssl() ? 'https' : 'http';
	} elseif ( 'admin' === $scheme || 'login' === $scheme || 'login_post' === $scheme || 'rpc' === $scheme ) {
		$scheme = is_ssl() || force_ssl_admin() ? 'https' : 'http';
	} elseif ( 'http' !== $scheme && 'https' !== $scheme && 'relative' !== $scheme ) {
		$scheme = is_ssl() ? 'https' : 'http';
	}

	$url = trim( $url );
	if ( str_starts_with( $url, '//' ) ) {
		$url = 'http:' . $url;
	}

	if ( 'relative' === $scheme ) {
		$url = ltrim( preg_replace( '#^\w+://[^/]*#', '', $url ) );
		if ( '' !== $url && '/' === $url[0] ) {
			$url = '/' . ltrim( $url, "/ \t\n\r\0\x0B" );
		}
	} else {
		$url = preg_replace( '#^\w+://#', $scheme . '://', $url );
	}

	/**
	 * Filters the resulting URL after setting the scheme.
	 *
	 * @since 3.4.0
	 *
	 * @param string      $url         The complete URL including scheme and path.
	 * @param string      $scheme      Scheme applied to the URL. One of 'http', 'https', or 'relative'.
	 * @param string|null $orig_scheme Scheme requested for the URL. One of 'http', 'https', 'login',
	 *                                 'login_post', 'admin', 'relative', 'rest', 'rpc', or null.
	 */
	return apply_filters( 'set_url_scheme', $url, $scheme, $orig_scheme );
}

// wp-includes/load.php lines 1659-1673 (WordPress 7.1.3).
function is_ssl() {
	if ( isset( $_SERVER['HTTPS'] ) ) {
		if ( 'on' === strtolower( $_SERVER['HTTPS'] ) ) {
			return true;
		}

		if ( '1' === (string) $_SERVER['HTTPS'] ) {
			return true;
		}
	} elseif ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' === (string) $_SERVER['SERVER_PORT'] ) ) {
		return true;
	}

	return false;
}

// wp-includes/kses.php lines 2020-2031 (WordPress 7.1.3).
function wp_kses_no_null( $content, $options = null ) {
	if ( ! isset( $options['slash_zero'] ) ) {
		$options = array( 'slash_zero' => 'remove' );
	}

	$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );
	if ( 'remove' === $options['slash_zero'] ) {
		$content = preg_replace( '/\\\\+0+/', '', $content );
	}

	return $content;
}

// wp-includes/pluggable.php lines 1557-1580 (WordPress 7.1.3).
	function wp_sanitize_redirect( $location ) {
		// Encode spaces.
		$location = str_replace( ' ', '%20', $location );

		$regex    = '/
		(
			(?: [\xC2-\xDF][\x80-\xBF]        # double-byte sequences   110xxxxx 10xxxxxx
			|   \xE0[\xA0-\xBF][\x80-\xBF]    # triple-byte sequences   1110xxxx 10xxxxxx * 2
			|   [\xE1-\xEC][\x80-\xBF]{2}
			|   \xED[\x80-\x9F][\x80-\xBF]
			|   [\xEE-\xEF][\x80-\xBF]{2}
			|   \xF0[\x90-\xBF][\x80-\xBF]{2} # four-byte sequences   11110xxx 10xxxxxx * 3
			|   [\xF1-\xF3][\x80-\xBF]{3}
			|   \xF4[\x80-\x8F][\x80-\xBF]{2}
		){1,40}                              # ...one or more times
		)/x';
		$location = preg_replace_callback( $regex, '_wp_sanitize_utf8_in_redirect', $location );
		$location = preg_replace( '|[^a-z0-9-~+_.?#=&;,/:%!*\[\]()@]|i', '', $location );
		$location = wp_kses_no_null( $location );

		// Remove %0D and %0A from location.
		$strip = array( '%0d', '%0a', '%0D', '%0A' );
		return _deep_replace( $strip, $location );
	}

// wp-includes/pluggable.php lines 1594-1596 (WordPress 7.1.3).
	function _wp_sanitize_utf8_in_redirect( $matches ) {
		return urlencode( $matches[0] );
	}

// wp-includes/pluggable.php lines 1669-1736 (WordPress 7.1.3).
	function wp_validate_redirect( $location, $fallback_url = '' ) {
		$location = wp_sanitize_redirect( trim( $location, " \t\n\r\0\x08\x0B" ) );
		// Browsers will assume 'http' is your protocol, and will obey a redirect to a URL starting with '//'.
		if ( str_starts_with( $location, '//' ) ) {
			$location = 'http:' . $location;
		}

		/*
		 * In PHP 5 parse_url() may fail if the URL query part contains 'http://'.
		 * See https://bugs.php.net/bug.php?id=38143
		 */
		$cut  = strpos( $location, '?' );
		$test = $cut ? substr( $location, 0, $cut ) : $location;

		$lp = parse_url( $test );

		// Give up if malformed URL.
		if ( false === $lp ) {
			return $fallback_url;
		}

		// Allow only 'http' and 'https' schemes. No 'data:', etc.
		if ( isset( $lp['scheme'] ) && ! ( 'http' === $lp['scheme'] || 'https' === $lp['scheme'] ) ) {
			return $fallback_url;
		}

		if ( ! isset( $lp['host'] ) && ! empty( $lp['path'] ) && '/' !== $lp['path'][0] ) {
			$path = '';
			if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
				$path = dirname( parse_url( 'http://placeholder' . $_SERVER['REQUEST_URI'], PHP_URL_PATH ) . '?' );
				$path = wp_normalize_path( $path );
			}
			$location = '/' . ltrim( $path . '/', '/' ) . $location;
		}

		/*
		 * Reject if certain components are set but host is not.
		 * This catches URLs like https:host.com for which parse_url() does not set the host field.
		 */
		if ( ! isset( $lp['host'] ) && ( isset( $lp['scheme'] ) || isset( $lp['user'] ) || isset( $lp['pass'] ) || isset( $lp['port'] ) ) ) {
			return $fallback_url;
		}

		// Reject malformed components parse_url() can return on odd inputs.
		foreach ( array( 'user', 'pass', 'host' ) as $component ) {
			if ( isset( $lp[ $component ] ) && strpbrk( $lp[ $component ], ':/?#@' ) ) {
				return $fallback_url;
			}
		}

		$wpp = parse_url( home_url() );

		/**
		 * Filters the list of allowed hosts to redirect to.
		 *
		 * @since 2.3.0
		 *
		 * @param string[] $hosts An array of allowed host names.
		 * @param string   $host  The host name of the redirect destination; empty string if not set.
		 */
		$allowed_hosts = (array) apply_filters( 'allowed_redirect_hosts', array( $wpp['host'] ), $lp['host'] ?? '' );

		if ( isset( $lp['host'] ) && ( ! in_array( $lp['host'], $allowed_hosts, true ) && strtolower( $wpp['host'] ) !== $lp['host'] ) ) {
			$location = $fallback_url;
		}

		return $location;
	}
