<?php
/**
 * Block profile: WordPress core blocks.
 *
 * Measured ("verified"): heading, paragraph, button(s), image and the
 * structural blocks, in the plan's measurement on WordPress 7.1.2
 * (docs/recherche/2026-09-30-page-builder-messung/bloecke/README.md, section
 * "WordPress-Kern", raw data out/ergebnis-core.json): text, link and image
 * address live only in the markup, the image id in the block comment.
 * From core code, not measured: list, quote, cover, media-text and the image
 * caption and link — where they live is the block.json of each block in
 * WordPress 7.0.2 (wp-includes/blocks/<name>/block.json, "source"/"selector").
 *
 * Format: see includes/builders/class-block-reader.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'         => 'core',
	'builder'    => 'blocks',
	'verified'   => true,
	'source'     => 'bloecke/README.md (WordPress 7.1.2); block.json of WordPress 7.0.2',
	'namespaces' => array( 'core/' ),
	'id_attr'    => '', // Core blocks carry no element id: path ids.
	'blocks'     => array(

		// heading/block.json: content = rich-text, selector h1–h6. Measured: text only in the markup.
		'core/heading'    => array(
			'fields' => array(
				'text' => array( 'kind' => 'heading', 'from' => 'html_text', 'selector' => 'h1,h2,h3,h4,h5,h6' ),
			),
		),

		// paragraph/block.json: content = rich-text, selector p. Measured: text only in the markup.
		'core/paragraph'  => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => 'p' ),
			),
		),

		// button/block.json: text = rich-text "a,button", url = attribute href of "a". Measured: both only in the markup.
		'core/button'     => array(
			'fields' => array(
				'text' => array( 'kind' => 'text', 'from' => 'html_text', 'selector' => 'a,button' ),
				'url'  => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'a', 'attr' => 'href' ),
			),
		),
		'core/buttons'    => array( 'container' => true ),

		// image/block.json: url = img src, alt = img alt, caption = figcaption, href = "figure > a", id in the comment.
		// Measured: src in the markup, id in the comment (and as class wp-image-N).
		'core/image'      => array(
			'fields' => array(
				'image'   => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'id' ),
				'src'     => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'src' ),
				'alt'     => array( 'kind' => 'text', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'alt' ),
				'caption' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => 'figcaption', 'verified' => false ),
				'link'    => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => '> a', 'attr' => 'href', 'verified' => false ),
			),
		),

		// Structure: measured in the Twenty Twenty-Five patterns of the measurement (no text of their own).
		'core/group'      => array( 'container' => true ),
		'core/columns'    => array( 'container' => true ),
		'core/column'     => array( 'container' => true ),
		'core/spacer'     => array( 'container' => true ),

		// list/block.json: the items are core/list-item blocks; list-item content = rich-text, selector li.
		'core/list'       => array( 'container' => true ),
		'core/list-item'  => array(
			'verified' => false,
			'fields'   => array(
				'text' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => 'li' ),
			),
		),

		// quote/block.json: the text is inner blocks; citation = rich-text, selector cite.
		'core/quote'      => array(
			'verified' => false,
			'fields'   => array(
				'citation' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => 'cite' ),
			),
		),

		// cover/block.json: url and alt without source (comment); the saved markup shows an img with the same address.
		'core/cover'      => array(
			'verified' => false,
			'fields'   => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'id' ),
				'src'   => array(
					'kind'     => 'url',
					'from'     => 'html_attr',
					'selector' => 'img',
					'attr'     => 'src',
					'also'     => array( array( 'from' => 'attr', 'path' => 'url' ) ),
				),
				'alt'   => array(
					'kind'     => 'text',
					'from'     => 'html_attr',
					'selector' => 'img',
					'attr'     => 'alt',
					'also'     => array( array( 'from' => 'attr', 'path' => 'alt' ) ),
				),
			),
		),

		// media-text/block.json: mediaUrl = "figure img" src, mediaAlt = "figure img" alt, href = "figure a", mediaId in the comment.
		'core/media-text' => array(
			'verified' => false,
			'fields'   => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'mediaId' ),
				'src'   => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'src' ),
				'alt'   => array( 'kind' => 'text', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'alt' ),
				'link'  => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'a', 'attr' => 'href' ),
			),
		),

		// Locked: raw HTML, shortcodes, classic content and code (bloecke/README.md h)).
		'core/html'       => array( 'locked' => 'html block' ),
		'core/shortcode'  => array( 'locked' => 'shortcode' ),
		'core/freeform'   => array( 'locked' => 'classic content' ),
		'core/code'       => array( 'locked' => 'code element' ),

		// block/block.json: ref = id of the synced pattern; its content is another post.
		'core/block'      => array(
			'locked'   => 'global element',
			'global'   => true,
			'ref_attr' => 'ref',
		),
	),
);
