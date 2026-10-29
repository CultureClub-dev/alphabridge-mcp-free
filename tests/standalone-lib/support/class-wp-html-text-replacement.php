<?php
/*
 * Fixed copy for the Pro test suite only: wp-includes/html-api/class-wp-html-text-replacement.php from WordPress 7.0.2
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
 * HTML API: WP_HTML_Text_Replacement class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 6.2.0
 */

/**
 * Core class used by the HTML tag processor as a data structure for replacing
 * existing content from start to end, allowing to drastically improve performance.
 *
 * This class is for internal usage of the WP_HTML_Tag_Processor class.
 *
 * @access private
 * @since 6.2.0
 * @since 6.5.0 Replace `end` with `length` to more closely match `substr()`.
 *
 * @see WP_HTML_Tag_Processor
 */
class WP_HTML_Text_Replacement {
	/**
	 * Byte offset into document where replacement span begins.
	 *
	 * @since 6.2.0
	 *
	 * @var int
	 */
	public $start;

	/**
	 * Byte length of span being replaced.
	 *
	 * @since 6.5.0
	 *
	 * @var int
	 */
	public $length;

	/**
	 * Span of text to insert in document to replace existing content from start to end.
	 *
	 * @since 6.2.0
	 *
	 * @var string
	 */
	public $text;

	/**
	 * Constructor.
	 *
	 * @since 6.2.0
	 *
	 * @param int    $start  Byte offset into document where replacement span begins.
	 * @param int    $length Byte length of span in document being replaced.
	 * @param string $text   Span of text to insert in document to replace existing content from start to end.
	 */
	public function __construct( int $start, int $length, string $text ) {
		$this->start  = $start;
		$this->length = $length;
		$this->text   = $text;
	}
}
