<?php
/*
 * Fixed copy for the Pro test suite only: wp-includes/html-api/class-wp-html-span.php from WordPress 7.0.2
 * (wp-includes/version.php: $wp_version = '7.0.2'), taken from the local core
 * copy ~/.wp-env/wp-env-wporg-4.0.0-f26facd6/WordPress on 02.10.2026. Unchanged
 * below this comment (the file's own first line, "<?php", is the line above).
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors, https://wordpress.org/about/license/.
 *
 * Not shipped: test/ lies outside the plugin folder that build-pro.sh packs,
 * and build-pro.sh checks the package for these copies. The plugin uses
 * WordPress' own classes at run time.
 */
/**
 * HTML API: WP_HTML_Span class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 6.2.0
 */

/**
 * Core class used by the HTML tag processor to represent a textual span
 * inside an HTML document.
 *
 * This is a two-tuple in disguise, used to avoid the memory overhead
 * involved in using an array for the same purpose.
 *
 * This class is for internal usage of the WP_HTML_Tag_Processor class.
 *
 * @access private
 * @since 6.2.0
 * @since 6.5.0 Replaced `end` with `length` to more closely align with `substr()`.
 *
 * @see WP_HTML_Tag_Processor
 */
class WP_HTML_Span {
	/**
	 * Byte offset into document where span begins.
	 *
	 * @since 6.2.0
	 *
	 * @var int
	 */
	public $start;

	/**
	 * Byte length of this span.
	 *
	 * @since 6.5.0
	 *
	 * @var int
	 */
	public $length;

	/**
	 * Constructor.
	 *
	 * @since 6.2.0
	 *
	 * @param int $start  Byte offset into document where replacement span begins.
	 * @param int $length Byte length of span.
	 */
	public function __construct( int $start, int $length ) {
		$this->start  = $start;
		$this->length = $length;
	}
}
