<?php
/*
 * Fixed copy for the test suite only: wp-includes/class-wp-block-parser-block.php from WordPress 7.0.2
 * (wp-includes/version.php: $wp_version = '7.0.2'), taken from the local core
 * copy ~/.wp-env/wp-env-wporg-4.0.0-f26facd6/WordPress on 01.10.2026. Unchanged
 * below this comment.
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors, https://wordpress.org/about/license/.
 *
 * Not shipped: tests/ is export-ignored (.gitattributes) and listed in
 * .distignore. The plugin uses WordPress' own parser at run time.
 */
/**
 * Block Serialization Parser
 *
 * @package WordPress
 */

/**
 * Class WP_Block_Parser_Block
 *
 * Holds the block structure in memory
 *
 * @since 5.0.0
 */
class WP_Block_Parser_Block {
	/**
	 * Name of block
	 *
	 * @example "core/paragraph"
	 *
	 * @since 5.0.0
	 * @var string
	 */
	public $blockName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

	/**
	 * Optional set of attributes from block comment delimiters
	 *
	 * @example null
	 * @example array( 'columns' => 3 )
	 *
	 * @since 5.0.0
	 * @var array|null
	 */
	public $attrs;

	/**
	 * List of inner blocks (of this same class)
	 *
	 * @since 5.0.0
	 * @var WP_Block_Parser_Block[]
	 */
	public $innerBlocks; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

	/**
	 * Resultant HTML from inside block comment delimiters
	 * after removing inner blocks
	 *
	 * @example "...Just <!-- wp:test /--> testing..." -> "Just testing..."
	 *
	 * @since 5.0.0
	 * @var string
	 */
	public $innerHTML; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

	/**
	 * List of string fragments and null markers where inner blocks were found
	 *
	 * @example array(
	 *   'innerHTML'    => 'BeforeInnerAfter',
	 *   'innerBlocks'  => array( block, block ),
	 *   'innerContent' => array( 'Before', null, 'Inner', null, 'After' ),
	 * )
	 *
	 * @since 5.0.0
	 * @var array
	 */
	public $innerContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

	/**
	 * Constructor.
	 *
	 * Will populate object properties from the provided arguments.
	 *
	 * @since 5.0.0
	 *
	 * @param string $name          Name of block.
	 * @param array  $attrs         Optional set of attributes from block comment delimiters.
	 * @param array  $inner_blocks  List of inner blocks (of this same class).
	 * @param string $inner_html    Resultant HTML from inside block comment delimiters after removing inner blocks.
	 * @param array  $inner_content List of string fragments and null markers where inner blocks were found.
	 */
	public function __construct( $name, $attrs, $inner_blocks, $inner_html, $inner_content ) {
		$this->blockName    = $name;          // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$this->attrs        = $attrs;
		$this->innerBlocks  = $inner_blocks;  // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$this->innerHTML    = $inner_html;    // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$this->innerContent = $inner_content; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}
}
