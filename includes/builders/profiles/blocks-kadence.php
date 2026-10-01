<?php
/**
 * Block profile: Kadence Blocks.
 *
 * Measured ("verified") in the plan's measurement with Kadence Blocks 3.7.12
 * on WordPress 7.1.2 (docs/recherche/2026-09-30-page-builder-messung/bloecke/
 * README.md, section "Kadence Blocks 3.7.12"; raw data out/ergebnis-kadence.json,
 * registered attributes out/inventar.json), on the vendor's "Example Page":
 * - heading and text (kadence/advancedheading, htmlTag p for text): only in
 *   the markup, and the page shows the markup's inner text;
 * - button (kadence/singlebtn): text AND link only in the block comment. The
 *   block saves no markup at all; Kadence builds it from the attributes, the
 *   text unfiltered (so an "<x>" in it becomes an element) and the link
 *   through do_shortcode() — which is why a writer escapes the text and
 *   refuses "[" in a link (plan §6, §8);
 * - image: address and alt in the markup, the id in the comment (and as the
 *   class wp-image-N).
 * Kadence's own CSS per block (kadenceBlockCSS) is never read (the reader
 * refuses CSS paths). The free version has no raw-HTML block (registry,
 * README h)).
 *
 * Format: see includes/builders/class-block-reader.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'         => 'kadence',
	'builder'    => 'kadence',
	'verified'   => true,
	'source'     => 'bloecke/README.md Kadence Blocks (3.7.12, WordPress 7.1.2), out/ergebnis-kadence.json, out/inventar.json',
	'namespaces' => array( 'kadence/' ),
	'id_attr'    => 'uniqueID', // Every Kadence block of the measured page carries uniqueID.
	'blocks'     => array(

		// inventar.json: content = html, selector "h1,h2,h3,h4,h5,h6,p.wp-block-kadence-advancedheading,
		// span.wp-block-kadence-advancedheading,div.wp-block-kadence-advancedheading". Heading and
		// text (htmlTag p) in one block, the content is rich text: read as simple HTML.
		'kadence/advancedheading' => array(
			'fields' => array(
				'text' => array(
					'kind'     => 'html',
					'from'     => 'html_inner',
					'selector' => 'h1,h2,h3,h4,h5,h6,p.wp-block-kadence-advancedheading,span.wp-block-kadence-advancedheading,div.wp-block-kadence-advancedheading',
				),
			),
		),

		// Measured: text and link only in the comment; the block is self-closing.
		// text is rich text (the editor stores RichText HTML) and is output raw, so it
		// is read as a visitor sees it.
		'kadence/singlebtn'       => array(
			'fields' => array(
				'text' => array( 'kind' => 'text', 'from' => 'attr', 'path' => 'text' ),
				'url'  => array( 'kind' => 'url', 'from' => 'attr', 'path' => 'link' ),
			),
		),

		// Structure: the button group around singlebtn (4 on the measured page).
		'kadence/advancedbtn'     => array( 'container' => true ),

		// inventar.json: url = img src, alt = img alt, caption = figcaption. Measured: src and
		// alt in the markup, id in the comment. The caption is not in the measured page.
		'kadence/image'           => array(
			'fields' => array(
				'image'   => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'id' ),
				'src'     => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'src' ),
				'alt'     => array( 'kind' => 'text', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'alt' ),
				'caption' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => 'figcaption', 'verified' => false ),
			),
		),

		// Structure: 7 row layouts and 17 columns on the measured page, no text of their own.
		'kadence/rowlayout'       => array( 'container' => true ),
		'kadence/column'          => array( 'container' => true ),
	),
);
