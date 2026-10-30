<?php
/*
 * Fixed copy for the test suite of AlphaBridge MCP only: wp-includes/class-wp-filter-sentinel.php from WordPress 7.1.2
 * (wp-includes/version.php: $wp_version = '7.1.2'), taken from the release
 * package that WordPress Playground keeps, ~/.wordpress-playground/7.1.2.zip,
 * on 03.10.2026. Unchanged below this comment (the file's own first line,
 * "<?php", is the line above).
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors, https://wordpress.org/about/license/.
 *
 * Not shipped: tests/ is left out of every release (.distignore, and
 * export-ignore in .gitattributes), and the CI job checks the package for it.
 * Taken over from AlphaBridge MCP Pro's test suite with the abilities tests
 * (4.12.0).
 */
/**
 * Filter Sentinel API.
 *
 * @package WordPress
 * @since 7.1.0
 */

declare( strict_types = 1 );

/**
 * Marker object used as a filter's default value when any user value — including
 * `null`, `false`, or arbitrary objects — must remain distinguishable from the
 * "no filter modified this" case.
 *
 * Each instance is unique by identity. Compare returned values with `===` against
 * the original sentinel to detect that no filter callback replaced it.
 *
 * Filter callbacks that want to pass through without modifying the value should
 * return the received value unchanged. Returning a freshly constructed
 * `WP_Filter_Sentinel` is treated as a replacement, not as pass-through.
 *
 * @since 7.1.0
 */
final class WP_Filter_Sentinel {}
