<?php
/**
 * Block profile: Pagelayer (blocks pagelayer/pl_*).
 *
 * Measured ("verified") in the plan's measurement with Pagelayer 2.2.2 on
 * WordPress 7.1.2 (docs/recherche/2026-09-30-page-builder-messung/
 * seedprod-pagelayer/README.md, section "Pagelayer"; raw data out/pl-*.json):
 * - the page renders from the blocks in post_content on every request; the
 *   content between the comments wins over the attribute "text"
 *   (shortcode_functions.php:144-147);
 * - heading and text (pl_heading, pl_text): the content of the block is the
 *   HTML, tag included (<h1>…</h1>, <p>…</p>). After a save in Pagelayer's
 *   editor the attribute "text" holds a copy of it;
 * - button (pl_btn): text and link only in the comment (the editor saves it
 *   self-closing);
 * - image (pl_image): only an attachment id (or an address) in "id", address,
 *   srcset and alt come from the media library when it renders; "link" is
 *   the link around it.
 * The tree in the post meta pagelayer-data is a derived copy that only
 * Pagelayer's abilities keep (signatures.php: copies, role copy);
 * post_content is the source.
 * Locked after the measurement (README g)): pl_embed (raw HTML, scripts
 * included, reach the page as they are), pl_shortcodes (run on render) and
 * pl_missing (saved as it is). The attributes ele_css and ele_attributes are
 * never read (the reader refuses CSS and attribute paths).
 *
 * Format: see includes/builders/class-block-reader.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'         => 'pagelayer',
	'builder'    => 'pagelayer',
	'verified'   => true,
	'source'     => 'seedprod-pagelayer/README.md Pagelayer (2.2.2, WordPress 7.1.2), out/pl-1b-kontrolle.json, out/pl-5-nach-editor.json',
	'namespaces' => array( 'pagelayer/' ),
	'id_attr'    => 'pagelayer-id', // The id Pagelayer's own abilities address elements by (update-element).
	'blocks'     => array(

		// Measured: <h1>…</h1> as the block content; after an editor save the same HTML in "text".
		'pagelayer/pl_heading'    => array(
			'fields' => array(
				'text' => array(
					'kind' => 'heading',
					'from' => 'html_text',
					'also' => array( array( 'from' => 'attr', 'path' => 'text' ) ),
				),
			),
		),

		// Measured: <p>…</p> as the block content; after an editor save the same HTML in "text".
		'pagelayer/pl_text'       => array(
			'fields' => array(
				'text' => array(
					'kind' => 'html',
					'from' => 'html_inner',
					'also' => array( array( 'from' => 'attr', 'path' => 'text' ) ),
				),
			),
		),

		// Measured: text and link only in the comment (README a), c)).
		'pagelayer/pl_btn'        => array(
			'fields' => array(
				'text' => array( 'kind' => 'text', 'from' => 'attr', 'path' => 'text' ),
				'url'  => array( 'kind' => 'url', 'from' => 'attr', 'path' => 'link' ),
			),
		),

		// Measured: "id" = attachment id ("4"); README a): an attachment id or an address.
		// "link" goes with link_type custom_url.
		'pagelayer/pl_image'      => array(
			'fields' => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'id' ),
				'link'  => array( 'kind' => 'url', 'from' => 'attr', 'path' => 'link' ),
			),
		),

		// Structure: row and column around the measured widgets.
		'pagelayer/pl_row'        => array( 'container' => true ),
		'pagelayer/pl_col'        => array( 'container' => true ),

		// Measured after an editor save (pl-5-nach-editor.json): the page's own properties
		// (title, status, date, author, featured image) as a self-closing block. Nothing of it
		// is visible content of the page; title and status are changed with wp_update_post.
		'pagelayer/pl_post_props' => array( 'container' => true ),

		// README g): raw HTML and scripts reach the page as they are (measured), shortcodes
		// run when the page renders (measured), pl_missing is kept as saved (shortcodes.php:8244).
		'pagelayer/pl_embed'      => array( 'locked' => 'html block' ),
		'pagelayer/pl_shortcodes' => array( 'locked' => 'shortcode' ),
		'pagelayer/pl_missing'    => array( 'locked' => 'unknown element type' ),
	),
);
