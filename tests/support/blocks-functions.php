<?php
/*
 * Fixed copy for the test suite only: six functions from wp-includes/blocks.php
 * of WordPress 7.0.2 (wp-includes/version.php: $wp_version = '7.0.2'), taken
 * from the local core copy ~/.wp-env/wp-env-wporg-4.0.0-f26facd6/WordPress on
 * 01.10.2026, each unchanged below its line note:
 *   serialize_block_attributes(): blocks.php lines 1628-1659
 *   strip_core_block_namespace(): blocks.php lines 1661-1677
 *   get_comment_delimited_block_content(): blocks.php lines 1679-1709
 *   serialize_block(): blocks.php lines 1711-1752
 *   serialize_blocks(): blocks.php lines 1754-1779
 *   parse_blocks(): blocks.php lines 2441-2486
 * strip_core_block_namespace() and get_comment_delimited_block_content() come
 * along because serialize_block() calls them.
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors, https://wordpress.org/about/license/.
 *
 * Not shipped: tests/ is export-ignored (.gitattributes) and listed in
 * .distignore. The plugin calls WordPress' own functions at run time.
 */

// wp-includes/blocks.php lines 1628-1659 (WordPress 7.0.2).
/**
 * Given an array of attributes, returns a string in the serialized attributes
 * format prepared for post content.
 *
 * The serialized result is a JSON-encoded string, with unicode escape sequence
 * substitution for characters which might otherwise interfere with embedding
 * the result in an HTML comment.
 *
 * This function must produce output that remains in sync with the output of
 * the serializeAttributes JavaScript function in the block editor in order
 * to ensure consistent operation between PHP and JavaScript.
 *
 * @since 5.3.1
 *
 * @param array $block_attributes Attributes object.
 * @return string Serialized attributes.
 */
function serialize_block_attributes( $block_attributes ) {
	$encoded_attributes = wp_json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return strtr(
		$encoded_attributes,
		array(
			'\\\\' => '\\u005c',
			'--'   => '\\u002d\\u002d',
			'<'    => '\\u003c',
			'>'    => '\\u003e',
			'&'    => '\\u0026',
			'\\"'  => '\\u0022',
		)
	);
}

// wp-includes/blocks.php lines 1661-1677 (WordPress 7.0.2).
/**
 * Returns the block name to use for serialization. This will remove the default
 * "core/" namespace from a block name.
 *
 * @since 5.3.1
 *
 * @param string|null $block_name Optional. Original block name. Null if the block name is unknown,
 *                                e.g. Classic blocks have their name set to null. Default null.
 * @return string Block name to use for serialization.
 */
function strip_core_block_namespace( $block_name = null ) {
	if ( is_string( $block_name ) && str_starts_with( $block_name, 'core/' ) ) {
		return substr( $block_name, 5 );
	}

	return $block_name;
}

// wp-includes/blocks.php lines 1679-1709 (WordPress 7.0.2).
/**
 * Returns the content of a block, including comment delimiters.
 *
 * @since 5.3.1
 *
 * @param string|null $block_name       Block name. Null if the block name is unknown,
 *                                      e.g. Classic blocks have their name set to null.
 * @param array       $block_attributes Block attributes.
 * @param string      $block_content    Block save content.
 * @return string Comment-delimited block content.
 */
function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
	if ( is_null( $block_name ) ) {
		return $block_content;
	}

	$serialized_block_name = strip_core_block_namespace( $block_name );
	$serialized_attributes = empty( $block_attributes ) ? '' : serialize_block_attributes( $block_attributes ) . ' ';

	if ( empty( $block_content ) ) {
		return sprintf( '<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes );
	}

	return sprintf(
		'<!-- wp:%s %s-->%s<!-- /wp:%s -->',
		$serialized_block_name,
		$serialized_attributes,
		$block_content,
		$serialized_block_name
	);
}

// wp-includes/blocks.php lines 1711-1752 (WordPress 7.0.2).
/**
 * Returns the content of a block, including comment delimiters, serializing all
 * attributes from the given parsed block.
 *
 * This should be used when preparing a block to be saved to post content.
 * Prefer `render_block` when preparing a block for display. Unlike
 * `render_block`, this does not evaluate a block's `render_callback`, and will
 * instead preserve the markup as parsed.
 *
 * @since 5.3.1
 *
 * @param array $block {
 *     An associative array of a single parsed block object. See WP_Block_Parser_Block.
 *
 *     @type string|null $blockName    Name of block.
 *     @type array       $attrs        Attributes from block comment delimiters.
 *     @type array[]     $innerBlocks  List of inner blocks. An array of arrays that
 *                                     have the same structure as this one.
 *     @type string      $innerHTML    HTML from inside block comment delimiters.
 *     @type array       $innerContent List of string fragments and null markers where
 *                                     inner blocks were found.
 * }
 * @return string String of rendered HTML.
 */
function serialize_block( $block ) {
	$block_content = '';

	$index = 0;
	foreach ( $block['innerContent'] as $chunk ) {
		$block_content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $index++ ] );
	}

	if ( ! is_array( $block['attrs'] ) ) {
		$block['attrs'] = array();
	}

	return get_comment_delimited_block_content(
		$block['blockName'],
		$block['attrs'],
		$block_content
	);
}

// wp-includes/blocks.php lines 1754-1779 (WordPress 7.0.2).
/**
 * Returns a joined string of the aggregate serialization of the given
 * parsed blocks.
 *
 * @since 5.3.1
 *
 * @param array[] $blocks {
 *     Array of block structures.
 *
 *     @type array ...$0 {
 *         An associative array of a single parsed block object. See WP_Block_Parser_Block.
 *
 *         @type string|null $blockName    Name of block.
 *         @type array       $attrs        Attributes from block comment delimiters.
 *         @type array[]     $innerBlocks  List of inner blocks. An array of arrays that
 *                                         have the same structure as this one.
 *         @type string      $innerHTML    HTML from inside block comment delimiters.
 *         @type array       $innerContent List of string fragments and null markers where
 *                                         inner blocks were found.
 *     }
 * }
 * @return string String of rendered HTML.
 */
function serialize_blocks( $blocks ) {
	return implode( '', array_map( 'serialize_block', $blocks ) );
}

// wp-includes/blocks.php lines 2441-2486 (WordPress 7.0.2).
/**
 * Parses blocks out of a content string.
 *
 * Given an HTML document, this function fully-parses block content, producing
 * a tree of blocks and their contents, as well as top-level non-block content,
 * which will appear as a block with no `blockName`.
 *
 * This function can be memory heavy for certain documents, particularly those
 * with deeply-nested blocks or blocks with extensive attribute values. Further,
 * this function must parse an entire document in one atomic operation.
 *
 * If the entire parsed document is not necessary, consider using {@see WP_Block_Processor}
 * instead, as it provides a streaming and low-overhead interface for finding blocks.
 *
 * @since 5.0.0
 *
 * @param string $content Post content.
 * @return array[] {
 *     Array of block structures.
 *
 *     @type array ...$0 {
 *         An associative array of a single parsed block object. See WP_Block_Parser_Block.
 *
 *         @type string|null $blockName    Name of block.
 *         @type array       $attrs        Attributes from block comment delimiters.
 *         @type array[]     $innerBlocks  List of inner blocks. An array of arrays that
 *                                         have the same structure as this one.
 *         @type string      $innerHTML    HTML from inside block comment delimiters.
 *         @type array       $innerContent List of string fragments and null markers where
 *                                         inner blocks were found.
 *     }
 * }
 */
function parse_blocks( $content ) {
	/**
	 * Filter to allow plugins to replace the server-side block parser.
	 *
	 * @since 5.0.0
	 *
	 * @param string $parser_class Name of block parser class.
	 */
	$parser_class = apply_filters( 'block_parser_class', 'WP_Block_Parser' );

	$parser = new $parser_class();
	return $parser->parse( $content );
}
