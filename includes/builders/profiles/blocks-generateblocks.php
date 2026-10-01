<?php
/**
 * Block profile: GenerateBlocks, the 2.x blocks (default on a new
 * installation) and the 1.x blocks, which stay registered next to them.
 *
 * Measured ("verified") in the plan's measurement with GenerateBlocks 2.4.1 on
 * WordPress 7.1.2 (docs/recherche/2026-09-30-page-builder-messung/bloecke/
 * README.md, sections "GenerateBlocks 2.x" and "1.x"; raw data
 * out/ergebnis-gb2.json, out/ergebnis-gb1.json, out/gb2/kodierung-nachmessung.json;
 * registered attributes out/inventar.json):
 * - text, link and image address are in the markup, and the page shows the
 *   markup (render_block returns the saved HTML and adds CSS);
 * - 2.x keeps a second copy of link and image address in htmlAttributes.href
 *   and htmlAttributes.src. Changing only that copy is invisible, changing
 *   only the markup leaves it stale, so the reader compares them;
 * - the image id is only in the block comment (mediaId);
 * - with useDynamicData (1.x) the saved markup is a placeholder: GenerateBlocks
 *   replaces e.g. href="#" with the permalink when it renders.
 * Locked after the measurement's list (README h)): generateblocks/shape (SVG
 * markup). The attributes css, styles and htmlAttributes are never read as
 * fields (the reader refuses such paths; htmlAttributes is only compared).
 *
 * Format: see includes/builders/class-block-reader.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'         => 'generateblocks',
	'builder'    => 'generateblocks',
	'verified'   => true,
	'source'     => 'bloecke/README.md GenerateBlocks 2.x and 1.x (GenerateBlocks 2.4.1, WordPress 7.1.2), out/ergebnis-gb2.json, out/ergebnis-gb1.json, out/inventar.json',
	'namespaces' => array( 'generateblocks/' ),
	'id_attr'    => 'uniqueId', // Every measured block, 1.x and 2.x, carries uniqueId.
	'blocks'     => array(

		/* ---------------------------------------------------------- 2.x */

		// One block for heading (tagName h2), text (p) and button (a). inventar.json:
		// content = rich-text, selector .gb-text. Measured: text only in the markup.
		// The link of a button is the element itself (a.gb-text), never a link inside
		// the text; its copy is htmlAttributes.href.
		'generateblocks/text'      => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => '.gb-text' ),
				'url'  => array(
					'kind'     => 'url',
					'from'     => 'html_attr',
					'selector' => 'a.gb-text',
					'attr'     => 'href',
					'also'     => array( array( 'from' => 'attr', 'path' => 'htmlAttributes.href' ) ),
				),
			),
		),

		// Measured: src in the markup and in htmlAttributes.src, mediaId only in the comment.
		// alt is not in the measured sample; it is written the same way as src and title
		// (htmlAttributes mirrored into the tag, measured for href, src and title).
		'generateblocks/media'     => array(
			'fields' => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'mediaId' ),
				'src'   => array(
					'kind'     => 'url',
					'from'     => 'html_attr',
					'selector' => 'img',
					'attr'     => 'src',
					'also'     => array( array( 'from' => 'attr', 'path' => 'htmlAttributes.src' ) ),
				),
				'alt'   => array(
					'kind'     => 'text',
					'from'     => 'html_attr',
					'selector' => 'img',
					'attr'     => 'alt',
					'verified' => false,
					'also'     => array( array( 'from' => 'attr', 'path' => 'htmlAttributes.alt' ) ),
				),
			),
		),

		// Structure: 4 and 7 elements in the two measured patterns, no text of their own.
		'generateblocks/element'   => array( 'container' => true ),

		// README h): the SVG markup itself (attribute html = .gb-shape).
		'generateblocks/shape'     => array( 'locked' => 'svg markup' ),

		/* ---------------------------------------------------------- 1.x */

		// Heading (element h3) and text (element div). blocks.js:33: content from
		// .gb-headline-text — the element itself, or a span next to an icon.
		'generateblocks/headline'  => array(
			'locked_when' => array( 'useDynamicData' => 'dynamic value' ),
			'fields'      => array(
				'text' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => '.gb-headline-text' ),
			),
		),

		// blocks.js:15: text from .gb-button-text, url from href of .gb-button.
		'generateblocks/button'    => array(
			'locked_when' => array( 'useDynamicData' => 'dynamic value' ),
			'fields'      => array(
				'text' => array( 'kind' => 'text', 'from' => 'html_text', 'selector' => '.gb-button-text' ),
				'url'  => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => '.gb-button', 'attr' => 'href' ),
			),
		),

		// Measured: src and alt in the markup, mediaId in the comment.
		'generateblocks/image'     => array(
			'locked_when' => array( 'useDynamicData' => 'dynamic value' ),
			'fields'      => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'mediaId' ),
				'src'   => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'src' ),
				'alt'   => array( 'kind' => 'text', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'alt' ),
			),
		),

		// Structure: 4 containers in the measured 1.x pattern.
		'generateblocks/container' => array( 'container' => true ),
	),
);
